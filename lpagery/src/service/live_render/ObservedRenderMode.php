<?php

namespace LPagery\service\live_render;

/**
 * The one place that answers "what Render Mode is this Page Set actually in?" (issue #240). Two
 * surfaces ask it now: the Manage table through {@see \LPagery\io\Mapper} and the Overview's recent
 * Page Sets list (issue #269), so the rule lives here rather than in either caller.
 *
 * Pure and static: it reads no database and calls no WordPress function beyond unserializing a blob
 * the caller already has in hand.
 */
class ObservedRenderMode
{
    /**
     * The Page Set's OBSERVED Render Mode: what its pages actually are, as opposed to the configured
     * mode that drives generation. Live and classic pages side by side read as `mixed`, which is the
     * normal intermediate state of a set mid-conversion.
     *
     * An EMPTY set has nothing to observe, so it reports its configured mode: a freshly created Live
     * Mode set must not present itself as Classic before its first page exists.
     *
     * @param int    $classic_count non-trashed pages without a live marker
     * @param int    $live_count    non-trashed pages carrying the live marker
     * @param string $configured    the configured Render Mode, used for the empty set
     */
    public static function derive(int $classic_count, int $live_count, string $configured): string
    {
        if ($live_count > 0 && $classic_count > 0) {
            return 'mixed';
        }
        if ($live_count > 0) {
            return 'live';
        }
        if ($classic_count > 0) {
            return 'classic';
        }
        return $configured;
    }

    /**
     * The CONFIGURED Render Mode stored in a Page Set's serialized `data` blob. Anything that is not
     * an explicit `live` is classic, so a set written before Live Mode existed reads as classic.
     *
     * @param mixed $serialized_data the raw `lpagery_process.data` column value
     */
    public static function configured($serialized_data): string
    {
        if ($serialized_data === null) {
            return 'classic';
        }
        $data = maybe_unserialize($serialized_data);
        if (is_array($data) && ($data['render_mode'] ?? null) === 'live') {
            return 'live';
        }
        return 'classic';
    }
}
