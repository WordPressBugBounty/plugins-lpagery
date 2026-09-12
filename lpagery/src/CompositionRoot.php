<?php

namespace LPagery;

use LPagery\controller\CreatePostController;
use LPagery\controller\DuplicatedSlugController;
use LPagery\controller\LiveModeConversionController;
use LPagery\controller\OverviewController;
use LPagery\controller\PostController;
use LPagery\controller\ProcessController;
use LPagery\controller\SlugController;
use LPagery\controller\TaxonomyController;
use LPagery\controller\UtilityController;
use LPagery\controller\ViewController;
use LPagery\data\LPageryDatabaseMigrator;
use LPagery\data\repository\AppTokenRepository;
use LPagery\data\repository\GeneratedPageRepository;
use LPagery\data\repository\PageMetaIndexRepository;
use LPagery\data\repository\PageSetRepository;
use LPagery\data\repository\SyncQueueRepository;
use LPagery\data\repository\ViewRepository;
use LPagery\data\SearchPostService;
use LPagery\io\asset\ViteAssetLoader;
use LPagery\io\CreatePageDebugger;
use LPagery\io\Mapper;
use LPagery\io\suite\SuiteClient;
use LPagery\service\onboarding\OnboardingService;
use LPagery\service\overview\OverviewSnapshotService;
use LPagery\service\queue\BackgroundWorkerReliability;
use LPagery\service\PageExportHandler;
use LPagery\service\caching\PurgeCachingPluginsService;
use LPagery\service\delete\DeletePageService;
use LPagery\service\delete\DeleteProcessService;
use LPagery\service\delete\ResetLPageryService;
use LPagery\service\duplicates\DuplicateSlugHelper;
use LPagery\service\duplicates\DuplicateSlugProvider;
use LPagery\service\DynamicPageAttributeHandler;
use LPagery\service\FindPostService;
use LPagery\service\InstallationDateHandler;
use LPagery\service\live_render\CachePluginDetector;
use LPagery\service\live_render\LiveBuilderCssHandler;
use LPagery\service\live_render\LiveBuilderSupport;
use LPagery\service\live_render\AttachmentDeleteGuard;
use LPagery\service\live_render\LiveCachePurger;
use LPagery\service\live_render\LiveDeactivationWarning;
use LPagery\service\live_render\OrphanedLivePageWarning;
use LPagery\service\live_render\LiveFragmentCache;
use LPagery\service\live_render\LiveShortcodeDeferral;
use LPagery\service\live_render\LivePagePurger;
use LPagery\service\image_endpoint\EdgeCacheProbe;
use LPagery\service\image_endpoint\EdgeCacheProbeStore;
use LPagery\service\image_endpoint\EdgeCacheProbeTransport;
use LPagery\service\image_endpoint\EndpointHealthProbe;
use LPagery\service\image_endpoint\EndpointHealthStore;
use LPagery\service\image_endpoint\EndpointHealthTransport;
use LPagery\service\image_endpoint\ImageEndpoint;
use LPagery\service\image_endpoint\VirtualImageResolver;
use LPagery\service\live_render\LiveImageMap;
use LPagery\service\live_render\VirtualImageAvailability;
use LPagery\service\live_render\VirtualImageDegradationWarning;
use LPagery\service\live_render\LiveMetaProxy;
use LPagery\service\live_render\LiveRenderController;
use LPagery\service\live_render\LiveListImageAttributes;
use LPagery\service\live_render\LiveRenderPass;
use LPagery\service\live_render\LiveRenderTelemetry;
use LPagery\service\live_render\LiveStubAdminGuard;
use LPagery\service\live_render\LiveStubResolver;
use LPagery\service\live_render\LiveTemplateDeleteGuard;
use LPagery\service\live_render\LiveTemplateSaveHandler;
use LPagery\service\image_lookup\AttachmentBasenameService;
use LPagery\service\preparation\InputParamProvider;
use LPagery\service\save_page\additional\AdditionalDataSaver;
use LPagery\service\save_page\additional\FifuHandler;
use LPagery\service\save_page\additional\MetaDataHandler;
use LPagery\service\save_page\additional\PagebuilderHandler;
use LPagery\service\save_page\additional\SeoPluginHandler;
use LPagery\service\save_page\CreatePostDelegate;
use LPagery\service\save_page\ManualChangeTracker;
use LPagery\service\save_page\PageSaver;
use LPagery\service\save_page\update\MaterializeService;
use LPagery\service\save_page\update\RenderModeBatchSwitcher;
use LPagery\service\settings\SettingsController;
use LPagery\service\substitution\ImageSubstitutionHandler;
use LPagery\service\substitution\Spintax;
use LPagery\service\substitution\SubstitutionDataPreparator;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\service\taxonomies\TaxonomySaveHandler;
use LPagery\multilingual\MultilingualPlugin;
use LPagery\multilingual\MultilingualPluginResolver;
use LPagery\suite\TokenValidator;
use LPagery\service\TrackingPermissionService;
use LPagery\service\view\ViewIndexBackfillWorker;
use LPagery\service\view\ViewMetaIndexService;
use LPagery\service\view\ViewRenderer;
use LPagery\service\view\ViewResolver;
/**
 * Hand-rolled composition root for the CreatePostController / CreatePostDelegate graph.
 *
 * One memoized typed getter per service, backed by {@see self::$shared}. Premium-conditional
 * wiring lives inline in the getters inside plain `lpagery_fs()->is_plan_or_trial__premium_only(...)`
 * guards, which Freemius removes whole from the free build (ADR 0011 null-wiring): the collaborator
 * is declared by a FREE interface, assigned inside the guard, and stays `null` in free. A guard whose
 * premium collaborator must be shared with premium-only callers reads it from
 * {@see lpagery_premium_root()}, and is nested inside a `can_use_premium_code__premium_only()` guard
 * because that — not the plan — is the condition under which `lpagery.php` defines that accessor. No
 * getter SIGNATURE on this root names a class that lives in a `__premium_only` file; such a class is
 * only ever named inside a guard the strip removes. Only entry points call {@see lpagery_root()};
 * services receive their dependencies via constructor.
 */
final class CompositionRoot {
    /** @var array<string, object> */
    private array $shared = [];

    public function settingsController() : SettingsController {
        return $this->shared[__FUNCTION__] ??= new SettingsController($this->pageSetRepository(), $this->livePagePurger(), $this->edgeCacheProbeStore());
    }

    public function mapper() : Mapper {
        return $this->shared[__FUNCTION__] ??= new Mapper($this->backgroundWorkerReliability(), $this->multilingualPlugin());
    }

    public function backgroundWorkerReliability() : BackgroundWorkerReliability {
        return $this->shared[__FUNCTION__] ??= new BackgroundWorkerReliability($this->syncQueueRepository());
    }

    public function overviewSnapshotService() : OverviewSnapshotService {
        return $this->shared[__FUNCTION__] ??= new OverviewSnapshotService(
            $this->generatedPageRepository(),
            $this->pageSetRepository(),
            $this->syncQueueRepository(),
            $this->settingsController(),
            $this->backgroundWorkerReliability()
        );
    }

    public function overviewController() : OverviewController {
        return $this->shared[__FUNCTION__] ??= new OverviewController($this->overviewSnapshotService());
    }

    public function searchPostService() : SearchPostService {
        return $this->shared[__FUNCTION__] ??= new SearchPostService($this->multilingualPlugin());
    }

    public function createPageDebugger() : CreatePageDebugger {
        return $this->shared[__FUNCTION__] ??= new CreatePageDebugger();
    }

    public function pageExportHandler() : PageExportHandler {
        return $this->shared[__FUNCTION__] ??= new PageExportHandler($this->pageSetRepository());
    }

    public function onboardingService() : OnboardingService {
        return $this->shared[__FUNCTION__] ??= new OnboardingService($this->multilingualPlugin());
    }

    public function substitutionHandler() : SubstitutionHandler {
        return $this->shared[__FUNCTION__] ??= new SubstitutionHandler(new Spintax(), new ImageSubstitutionHandler());
    }

    public function substitutionDataPreparator() : SubstitutionDataPreparator {
        return $this->shared[__FUNCTION__] ??= new SubstitutionDataPreparator();
    }

    public function findPostService() : FindPostService {
        return $this->shared[__FUNCTION__] ??= new FindPostService($this->generatedPageRepository(), $this->substitutionHandler());
    }

    public function dynamicPageAttributeHandler() : DynamicPageAttributeHandler {
        return $this->shared[__FUNCTION__] ??= new DynamicPageAttributeHandler($this->settingsController(), $this->generatedPageRepository(), $this->findPostService());
    }

    public function inputParamProvider() : InputParamProvider {
        if ( isset( $this->shared[__FUNCTION__] ) ) {
            return $this->shared[__FUNCTION__];
        }
        // ADR 0011 null-wiring. A plain premium `if`, not a ternary: Freemius removes the whole
        // statement from the free build, and the provider stays null there. The media graph is owned
        // by the premium root so the sheet-sync worker and the image AJAX endpoints share one instance.
        // The outer guard is `can_use_premium_code__premium_only` because that is what `lpagery.php`
        // defines `lpagery_premium_root()` behind; the inner one is the plan gate.
        $mediaParamProvider = null;
        return $this->shared[__FUNCTION__] = new InputParamProvider($this->settingsController(), $this->installationDateHandler(), $mediaParamProvider);
    }

    public function duplicateSlugHelper() : DuplicateSlugHelper {
        return $this->shared[__FUNCTION__] ??= new DuplicateSlugHelper($this->inputParamProvider(), $this->substitutionHandler(), $this->dynamicPageAttributeHandler());
    }

    public function duplicateSlugProvider() : DuplicateSlugProvider {
        return $this->shared[__FUNCTION__] ??= new DuplicateSlugProvider(
            $this->substitutionDataPreparator(),
            $this->duplicateSlugHelper(),
            $this->generatedPageRepository(),
            $this->pageSetRepository()
        );
    }

    /**
     * The single active Multilingual Plugin (WPML or Polylang), or null when none is active.
     *
     * Memoized through the resolver, which is the only place that decides. Services receive the
     * result via constructor and never resolve it themselves.
     */
    public function multilingualPlugin() : ?MultilingualPlugin {
        return $this->multilingualPluginResolver()->resolve();
    }

    private function multilingualPluginResolver() : MultilingualPluginResolver {
        return $this->shared[__FUNCTION__] ??= new MultilingualPluginResolver();
    }

    public function additionalDataSaver() : AdditionalDataSaver {
        if ( isset( $this->shared[__FUNCTION__] ) ) {
            return $this->shared[__FUNCTION__];
        }
        $substitutionHandler = $this->substitutionHandler();
        // ADR 0011 null-wiring: the handler is Standard-tier and its file is stripped from the free
        // build, so it is only ever named inside this guard. AdditionalDataSaver declares the free
        // {@see \LPagery\service\taxonomies\TaxonomyAssigner} interface and gets null in free.
        $taxonomyHandler = null;
        return $this->shared[__FUNCTION__] = new AdditionalDataSaver(
            new PagebuilderHandler($substitutionHandler),
            new SeoPluginHandler($substitutionHandler),
            $this->multilingualPlugin(),
            new FifuHandler(),
            $taxonomyHandler,
            new MetaDataHandler($substitutionHandler),
            $this->generatedPageRepository()
        );
    }

    public function pageSaver() : PageSaver {
        if ( isset( $this->shared[__FUNCTION__] ) ) {
            return $this->shared[__FUNCTION__];
        }
        // ADR 0011 null-wiring: updating an existing Generated Page is Extended-tier. Free gets null
        // and PageSaver never rewrites an existing page.
        $pageUpdateChecker = null;
        return $this->shared[__FUNCTION__] = new PageSaver(
            $this->generatedPageRepository(),
            $this->additionalDataSaver(),
            $pageUpdateChecker,
            $this->purgeCachingPluginsService()
        );
    }

    public function purgeCachingPluginsService() : PurgeCachingPluginsService {
        return $this->shared[__FUNCTION__] ??= new PurgeCachingPluginsService();
    }

    public function createPostDelegate() : CreatePostDelegate {
        if ( isset( $this->shared[__FUNCTION__] ) ) {
            return $this->shared[__FUNCTION__];
        }
        // ADR 0011 null-wiring: resolving which existing page a row updates is Extended-tier. The
        // handler is shared with the premium sheet-sync graph, so the premium root owns it.
        $pageUpdateDataProvider = null;
        return $this->shared[__FUNCTION__] = new CreatePostDelegate(
            $this->inputParamProvider(),
            $this->substitutionHandler(),
            $this->dynamicPageAttributeHandler(),
            $this->pageSaver(),
            $pageUpdateDataProvider,
            $this->substitutionDataPreparator(),
            $this->generatedPageRepository(),
            $this->pageSetRepository()
        );
    }

    public function createPostController() : CreatePostController {
        if ( isset( $this->shared[__FUNCTION__] ) ) {
            return $this->shared[__FUNCTION__];
        }
        // ADR 0011 null-wiring, in plain `if` form so Freemius takes both statements out of the free
        // build. Both collaborators are Extended-tier and shared with premium-only callers (the image
        // AJAX endpoints; the background switch enqueue), so the premium root owns the instances and
        // this graph names only the free interfaces. Materialize needs no such guard: it is free
        // (ADR 0019), so the worker's classic switch items convert on every tier.
        $attachmentSearchCache = null;
        $stripToStubService = null;
        return $this->shared[__FUNCTION__] = new CreatePostController(
            $this->createPostDelegate(),
            $this->settingsController(),
            $attachmentSearchCache,
            $this->syncQueueRepository(),
            $this->pageSetRepository(),
            $this->materializeService(),
            $stripToStubService
        );
    }

    // ---------------------------------------------------------------------
    // Render Mode conversion (ADR 0019). Materialize (Live to Classic) is
    // free, so the service, the batch loop and the controller live here; only
    // the Strip to Stub collaborator comes from the premium root.
    // ---------------------------------------------------------------------
    public function renderModeBatchSwitcher() : RenderModeBatchSwitcher {
        return $this->shared[__FUNCTION__] ??= new RenderModeBatchSwitcher($this->generatedPageRepository());
    }

    public function materializeService() : MaterializeService {
        return $this->shared[__FUNCTION__] ??= new MaterializeService(
            $this->generatedPageRepository(),
            $this->createPostDelegate(),
            $this->liveCachePurger(),
            $this->renderModeBatchSwitcher()
        );
    }

    public function liveModeConversionController() : LiveModeConversionController {
        if ( isset( $this->shared[__FUNCTION__] ) ) {
            return $this->shared[__FUNCTION__];
        }
        // The repositories are passed so both browser entry points can reject a conflict with a
        // background operation holding the set (issue #220 Phase 10); the page repository resolves a
        // per-page convert's post to its set. The stripper stays null below Extended and in the free
        // build, and the controller answers the same error either way.
        $stripToStubService = null;
        return $this->shared[__FUNCTION__] = new LiveModeConversionController(
            $this->materializeService(),
            $this->syncQueueRepository(),
            $this->generatedPageRepository(),
            $stripToStubService
        );
    }

    // ---------------------------------------------------------------------
    // Live-render graph (ADR 0007–0010). All classes are FREE-dir and reached
    // from FREE hook registrars (LiveRenderHooks) / free AJAX endpoints, so
    // their canonical getters live here on the free root. Byte-compatible with
    // the LiveRenderControllerFactory this replaces.
    // ---------------------------------------------------------------------
    /**
     * Shared per-request resolver so the meta proxy and the content/output-buffer layers hit the
     * same O(1) in-request stub cache. Public so the front-end hooks and the Image Endpoint can ask
     * its cheap site-wide gate before the rest of the Live Render graph is wired.
     */
    public function liveStubResolver() : LiveStubResolver {
        return $this->shared[__FUNCTION__] ??= new LiveStubResolver($this->generatedPageRepository());
    }

    public function liveBuilderSupport() : LiveBuilderSupport {
        return $this->shared[__FUNCTION__] ??= new LiveBuilderSupport();
    }

    public function virtualImageResolver() : VirtualImageResolver {
        return $this->shared[__FUNCTION__] ??= new VirtualImageResolver();
    }

    public function imageEndpoint() : ImageEndpoint {
        return $this->shared[__FUNCTION__] ??= new ImageEndpoint($this->liveStubResolver(), $this->virtualImageResolver(), $this->edgeCacheProbeStore());
    }

    public function edgeCacheProbeStore() : EdgeCacheProbeStore {
        return $this->shared[__FUNCTION__] ??= new EdgeCacheProbeStore();
    }

    public function edgeCacheProbeTransport() : EdgeCacheProbeTransport {
        return $this->shared[__FUNCTION__] ??= new EdgeCacheProbeTransport();
    }

    public function edgeCacheProbe() : EdgeCacheProbe {
        return $this->shared[__FUNCTION__] ??= new EdgeCacheProbe($this->edgeCacheProbeTransport(), $this->edgeCacheProbeStore(), $this->livePagePurger());
    }

    public function liveImageMap() : LiveImageMap {
        return $this->shared[__FUNCTION__] ??= new LiveImageMap($this->virtualImageAvailability());
    }

    public function virtualImageAvailability() : VirtualImageAvailability {
        return $this->shared[__FUNCTION__] ??= new VirtualImageAvailability($this->endpointHealthStore(), $this->settingsController());
    }

    public function endpointHealthStore() : EndpointHealthStore {
        return $this->shared[__FUNCTION__] ??= new EndpointHealthStore();
    }

    public function endpointHealthTransport() : EndpointHealthTransport {
        return $this->shared[__FUNCTION__] ??= new EndpointHealthTransport();
    }

    public function endpointHealthProbe() : EndpointHealthProbe {
        return $this->shared[__FUNCTION__] ??= new EndpointHealthProbe(
            $this->generatedPageRepository(),
            $this->endpointHealthTransport(),
            $this->endpointHealthStore(),
            $this->livePagePurger()
        );
    }

    public function livePagePurger() : LivePagePurger {
        return $this->shared[__FUNCTION__] ??= new LivePagePurger($this->generatedPageRepository(), $this->liveCachePurger());
    }

    public function liveFragmentCache() : LiveFragmentCache {
        return $this->shared[__FUNCTION__] ??= new LiveFragmentCache();
    }

    public function liveShortcodeDeferral() : LiveShortcodeDeferral {
        return $this->shared[__FUNCTION__] ??= new LiveShortcodeDeferral();
    }

    public function cachePluginDetector() : CachePluginDetector {
        return $this->shared[__FUNCTION__] ??= new CachePluginDetector();
    }

    public function installationDateHandler() : InstallationDateHandler {
        return $this->shared[__FUNCTION__] ??= new InstallationDateHandler();
    }

    public function trackingPermissionService() : TrackingPermissionService {
        return $this->shared[__FUNCTION__] ??= new TrackingPermissionService($this->installationDateHandler());
    }

    public function liveRenderPass() : LiveRenderPass {
        return $this->shared[__FUNCTION__] ??= new LiveRenderPass($this->substitutionHandler(), $this->liveImageMap());
    }

    public function liveListImageAttributes() : LiveListImageAttributes {
        return $this->shared[__FUNCTION__] ??= new LiveListImageAttributes($this->substitutionHandler());
    }

    public function liveBuilderCssHandler() : LiveBuilderCssHandler {
        return $this->shared[__FUNCTION__] ??= new LiveBuilderCssHandler($this->liveBuilderSupport());
    }

    public function liveCachePurger() : LiveCachePurger {
        return $this->shared[__FUNCTION__] ??= new LiveCachePurger($this->cachePluginDetector());
    }

    public function attachmentDeleteGuard() : AttachmentDeleteGuard {
        return $this->shared[__FUNCTION__] ??= new AttachmentDeleteGuard($this->generatedPageRepository(), $this->liveCachePurger());
    }

    public function liveRenderTelemetry() : LiveRenderTelemetry {
        return $this->shared[__FUNCTION__] ??= new LiveRenderTelemetry($this->liveBuilderSupport(), $this->cachePluginDetector(), $this->trackingPermissionService());
    }

    public function liveRenderController() : LiveRenderController {
        return $this->shared[__FUNCTION__] ??= new LiveRenderController(
            $this->liveStubResolver(),
            $this->liveRenderPass(),
            $this->liveBuilderCssHandler(),
            $this->liveFragmentCache(),
            $this->liveShortcodeDeferral(),
            $this->liveRenderTelemetry(),
            $this->liveListImageAttributes()
        );
    }

    public function liveMetaProxy() : LiveMetaProxy {
        return $this->shared[__FUNCTION__] ??= new LiveMetaProxy($this->liveStubResolver());
    }

    public function liveStubAdminGuard() : LiveStubAdminGuard {
        return $this->shared[__FUNCTION__] ??= new LiveStubAdminGuard($this->liveStubResolver());
    }

    public function liveTemplateSaveHandler() : LiveTemplateSaveHandler {
        return $this->shared[__FUNCTION__] ??= new LiveTemplateSaveHandler($this->generatedPageRepository(), $this->liveFragmentCache(), $this->liveCachePurger());
    }

    public function liveTemplateDeleteGuard() : LiveTemplateDeleteGuard {
        return $this->shared[__FUNCTION__] ??= new LiveTemplateDeleteGuard($this->generatedPageRepository());
    }

    public function liveDeactivationWarning() : LiveDeactivationWarning {
        return $this->shared[__FUNCTION__] ??= new LiveDeactivationWarning($this->generatedPageRepository());
    }

    public function orphanedLivePageWarning() : OrphanedLivePageWarning {
        return $this->shared[__FUNCTION__] ??= new OrphanedLivePageWarning($this->generatedPageRepository());
    }

    public function virtualImageDegradationWarning() : VirtualImageDegradationWarning {
        return $this->shared[__FUNCTION__] ??= new VirtualImageDegradationWarning($this->virtualImageAvailability(), $this->generatedPageRepository());
    }

    public function manualChangeTracker() : ManualChangeTracker {
        return $this->shared[__FUNCTION__] ??= new ManualChangeTracker($this->generatedPageRepository());
    }

    // ---------------------------------------------------------------------
    // Related-pages View graph (ADR-0001/0002). All classes are FREE-dir and
    // the [lpagery_view] render + index backfill must keep running on
    // downgraded sites, so their canonical getters live here on the free root
    // even though some callers are premium files (View create/edit AJAX,
    // premium backfill cron). Byte-compatible with the ViewControllerFactory /
    // ViewIndexBackfillWorkerFactory this replaces.
    // ---------------------------------------------------------------------
    private function pageMetaIndexRepository() : PageMetaIndexRepository {
        return $this->shared[__FUNCTION__] ??= new PageMetaIndexRepository();
    }

    public function viewMetaIndexService() : ViewMetaIndexService {
        return $this->shared[__FUNCTION__] ??= new ViewMetaIndexService($this->pageMetaIndexRepository());
    }

    public function viewResolver() : ViewResolver {
        return $this->shared[__FUNCTION__] ??= new ViewResolver($this->viewRepository(), $this->pageMetaIndexRepository(), $this->viewMetaIndexService());
    }

    public function viewRenderer() : ViewRenderer {
        return $this->shared[__FUNCTION__] ??= new ViewRenderer($this->generatedPageRepository(), $this->liveListImageAttributes());
    }

    public function viewIndexBackfillWorker() : ViewIndexBackfillWorker {
        return $this->shared[__FUNCTION__] ??= new ViewIndexBackfillWorker($this->pageMetaIndexRepository(), $this->viewMetaIndexService());
    }

    public function viewController() : ViewController {
        return $this->shared[__FUNCTION__] ??= new ViewController(
            $this->viewRepository(),
            $this->pageMetaIndexRepository(),
            $this->viewResolver(),
            $this->viewRenderer(),
            $this->viewIndexBackfillWorker(),
            $this->viewMetaIndexService(),
            $this->generatedPageRepository()
        );
    }

    public function syncQueueRepository() : SyncQueueRepository {
        return $this->shared[__FUNCTION__] ??= new SyncQueueRepository();
    }

    public function generatedPageRepository() : GeneratedPageRepository {
        return $this->shared[__FUNCTION__] ??= new GeneratedPageRepository($this->pageMetaIndexRepository(), $this->multilingualPlugin());
    }

    public function pageSetRepository() : PageSetRepository {
        return $this->shared[__FUNCTION__] ??= new PageSetRepository();
    }

    public function appTokenRepository() : AppTokenRepository {
        return $this->shared[__FUNCTION__] ??= new AppTokenRepository();
    }

    public function viewRepository() : ViewRepository {
        return $this->shared[__FUNCTION__] ??= new ViewRepository();
    }

    public function databaseMigrator() : LPageryDatabaseMigrator {
        return $this->shared[__FUNCTION__] ??= new LPageryDatabaseMigrator();
    }

    // ---------------------------------------------------------------------
    // Attachment basename index. FREE: the index is written from free hooks on
    // every upload so the Extended image search has something to read later.
    // The media graph that reads it lives on the premium companion root.
    // ---------------------------------------------------------------------
    public function attachmentBasenameService() : AttachmentBasenameService {
        return $this->shared[__FUNCTION__] ??= new AttachmentBasenameService();
    }

    // ---------------------------------------------------------------------
    // Free controllers + Suite integration. All FREE-dir and reached from the
    // free entry points (SuiteRestApi REST routes, AjaxActions AJAX endpoints),
    // so their canonical getters live here on the free root, the delete/reset chain
    // of the ProcessController graph included.
    // ---------------------------------------------------------------------
    /**
     * LPagery's own bulk page deletion, which applies the live template guard itself because it
     * bypasses `wp_delete_post` and therefore WordPress's own delete filters (ADR 0017).
     */
    public function deletePageService() : DeletePageService {
        return $this->shared[__FUNCTION__] ??= new DeletePageService($this->generatedPageRepository(), $this->liveTemplateDeleteGuard());
    }

    public function deleteProcessService() : DeleteProcessService {
        return $this->shared[__FUNCTION__] ??= new DeleteProcessService(
            $this->viewRepository(),
            $this->generatedPageRepository(),
            $this->syncQueueRepository(),
            $this->pageSetRepository(),
            $this->deletePageService()
        );
    }

    public function resetLPageryService() : ResetLPageryService {
        return $this->shared[__FUNCTION__] ??= new ResetLPageryService($this->deleteProcessService());
    }

    public function processController() : ProcessController {
        return $this->shared[__FUNCTION__] ??= new ProcessController(
            $this->mapper(),
            $this->resetLPageryService(),
            $this->pageExportHandler(),
            $this->liveBuilderSupport(),
            $this->generatedPageRepository(),
            $this->pageSetRepository()
        );
    }

    public function postController() : PostController {
        return $this->shared[__FUNCTION__] ??= new PostController($this->searchPostService(), $this->pageSetRepository(), $this->mapper());
    }

    public function slugController() : SlugController {
        return $this->shared[__FUNCTION__] ??= new SlugController();
    }

    public function taxonomyController() : TaxonomyController {
        return $this->shared[__FUNCTION__] ??= new TaxonomyController();
    }

    public function utilityController() : UtilityController {
        return $this->shared[__FUNCTION__] ??= new UtilityController($this->onboardingService(), $this->pageSetRepository());
    }

    public function duplicatedSlugController() : DuplicatedSlugController {
        return $this->shared[__FUNCTION__] ??= new DuplicatedSlugController($this->duplicateSlugProvider());
    }

    public function tokenValidator() : TokenValidator {
        return $this->shared[__FUNCTION__] ??= new TokenValidator();
    }

    public function suiteClient() : SuiteClient {
        return $this->shared[__FUNCTION__] ??= new SuiteClient();
    }

    public function viteAssetLoader() : ViteAssetLoader {
        return $this->shared[__FUNCTION__] ??= new ViteAssetLoader();
    }

}
