<?php

namespace LPagery\service\save_page\update;

/**
 * Strips a Generated Page, or a whole Page Set, back to Live Mode stubs (ADR 0009/0010/0019).
 *
 * Free-side type for the Strip to Stub service, which is Extended-tier and therefore lives in a
 * `__premium_only` file. The conversion controller and the background worker loopback are free code,
 * so they hold the stripper by this interface and refuse the request when it is `null`.
 */
interface RenderModeStripper extends RenderModeConverter
{
    /**
     * One foreground batch of a set-level strip, converting at most $limit pages and reporting how
     * many remain.
     *
     * @return array{converted:int,remaining:int,total:int,done:bool,process_id:int,target_type:string,failed_post_ids:int[]}
     */
    public function switch_process(int $process_id, int $limit): array;
}
