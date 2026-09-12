<?php

namespace LPagery\service\save_page\additional;

use LPagery\model\Params;
use LPagery\service\save_page\additional\pagebuilder\BeBuilderAdapter;
use LPagery\service\save_page\additional\pagebuilder\BreakdanceAdapter;
use LPagery\service\save_page\additional\pagebuilder\BricksAdapter;
use LPagery\service\save_page\additional\pagebuilder\BrizyAdapter;
use LPagery\service\save_page\additional\pagebuilder\ColibriAdapter;
use LPagery\service\save_page\additional\pagebuilder\DiviAdapter;
use LPagery\service\save_page\additional\pagebuilder\ElementorAdapter;
use LPagery\service\save_page\additional\pagebuilder\GutenbergAdapter;
use LPagery\service\save_page\additional\pagebuilder\PagebuilderAdapter;
use LPagery\service\save_page\additional\pagebuilder\SeedprodAdapter;
use LPagery\service\save_page\additional\pagebuilder\VisualComposerAdapter;
use LPagery\service\substitution\SubstitutionHandler;
use WP_Post;

/**
 * Facade + ordered registry for classic-mode pagebuilder handling.
 *
 * A plain constructible class wired via the CompositionRoot's additionalDataSaver() getter.
 * Internally it holds the ordered list of {@see PagebuilderAdapter}s and applies every adapter
 * whose supports() returns true (multi-fire) — registration order is identical to the historic
 * branch order.
 */
class PagebuilderHandler
{
    /** @var PagebuilderAdapter[] */
    private array $adapters;

    public function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->adapters = array(
            new Divi5Handler($substitutionHandler),
            new GutenbergAdapter(),
            new ElementorAdapter(),
            new BreakdanceAdapter($substitutionHandler),
            new BrizyAdapter($substitutionHandler),
            new VisualComposerAdapter($substitutionHandler),
            new BeBuilderAdapter($substitutionHandler),
            new DiviAdapter(),
            new SeedprodAdapter($substitutionHandler),
            new BricksAdapter($substitutionHandler),
            new ColibriAdapter(),
        );
    }


    public function lpagery_handle_pagebuilder($sourcePostId, $targetPostId, Params $params)
    {
        // Fetch the Template Page post once, then iterate the ordered adapter list.
        // We check the SOURCE post since the target content may already have backslashes stripped.
        $source_post = get_post($sourcePostId);
        if (!$source_post instanceof WP_Post) {
            return;
        }
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($source_post, $params)) {
                $adapter->apply($sourcePostId, $targetPostId, $params);
            }
        }
    }
}
