<?php

namespace LPagery\service\live_render;

use LPagery\data\repository\GeneratedPageRepository;

/**
 * Deactivation warning copy for Live Mode (Phase 9). A Live Mode page has no content of its own —
 * its body renders from the Template Page through the render pipeline this plugin provides — so
 * deactivating LPagery makes every live page serve empty until the plugin is reactivated. Its
 * processed (per-page-filename) images are served by the plugin's virtual image endpoint (ADR 0013),
 * so those images 404 while the plugin is deactivated too — unlike duplicated files, which survive it.
 * Surfaced inline under the LPagery row on the plugins screen only when live stubs actually exist, with
 * the affected page count and the escape hatch (Convert to Classic) for a permanent removal.
 */
class LiveDeactivationWarning
{
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }


    /**
     * The warning sentence with the live-page count, or null when no live stubs exist (nothing to
     * warn about). Plain text; the caller escapes it for the notice markup.
     */
    public function get_warning(): ?string
    {
        $count = $this->generatedPageRepository->count_live_stubs();
        if ($count <= 0) {
            return null;
        }

        return sprintf(
            /* translators: %d is the number of Live Mode pages that will stop rendering. */
            __('%d Live Mode pages will serve empty content and their processed images will return 404 until the plugin is reactivated. Convert them to Classic first if you are removing LPagery permanently.', 'lpagery'),
            $count
        );
    }
}
