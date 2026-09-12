<?php

namespace LPagery\io;

use LPagery\multilingual\MultilingualPlugin;
use LPagery\multilingual\PostLanguageData;
use LPagery\service\live_render\ObservedRenderMode;
use LPagery\service\queue\BackgroundRunStatus;
use LPagery\service\queue\BackgroundWorkerReliability;

class Mapper
{
    private BackgroundWorkerReliability $backgroundWorkerReliability;
    private ?MultilingualPlugin $multilingualPlugin;

    public function __construct(BackgroundWorkerReliability $backgroundWorkerReliability, ?MultilingualPlugin $multilingualPlugin = null)
    {
        $this->backgroundWorkerReliability = $backgroundWorkerReliability;
        $this->multilingualPlugin = $multilingualPlugin;
    }

    /**
     * The Page Language part of a post payload: code, display name, flag URL and the permalink
     * inside that language.
     *
     * Empty when no Multilingual Plugin is active or the post has no Page Language, so a payload
     * simply omits the fields instead of carrying nulls. Merge it after the plain fields: the
     * permalink it carries replaces the default-language one, so a link opens the page in its own
     * language.
     *
     * @return array<string, string>
     */
    public function language_payload(int $post_id): array
    {
        return $this->language_payload_from($this->get_language_data($post_id));
    }

    /**
     * @return array<string, string>
     */
    private function language_payload_from(?PostLanguageData $language_data): array
    {
        if ($language_data === null || $language_data->language_code === null) {
            return array();
        }

        $fields = array("language_code" => $language_data->language_code);
        if ($language_data->language_name !== null) {
            $fields["language_name"] = $language_data->language_name;
        }
        if ($language_data->flag_url !== null) {
            $fields["flag_url"] = $language_data->flag_url;
        }
        if ($language_data->permalink !== null) {
            $fields["permalink"] = $language_data->permalink;
        }
        return $fields;
    }

    private function get_language_data(int $post_id): ?PostLanguageData
    {
        return $this->multilingualPlugin === null ? null : $this->multilingualPlugin->get_language_data($post_id);
    }

    public function lpagery_map_post($post)
    {
        $result = array("id" => $post->ID,
            "title" => $post->post_title);
            
        if (isset($post->post_type)) {
            $post_type = get_post_type_object($post->post_type);
            if ($post_type) {
                $result["type_label"] = $post_type->labels->singular_name;
                $result["type"] = $post_type->name;
            }
        }

        if (isset($post->language_code)) {
            $result["language_code"] = $post->language_code;
        }
        return $result;
    }

    public function lpagery_map_post_extended($post)
    {
        if (!is_array($post)) {
            $post = (array)$post;
        }
        $array = array("id" => $post["ID"],
            "title" => $post["post_title"],
            "process_id" => $post["process_id"],
            "slug" => $post["replaced_slug"],
            "permalink" => get_permalink($post["ID"]));
        return array_merge($array, $this->language_payload((int)$post["ID"]));
    }

    public function lpagery_map_post_for_update_modal($post)
    {
        if (!is_array($post)) {
            $post = (array)$post;
        }
        $array = array("id" => $post["ID"],
            "title" => $post["post_title"],
            "process_id" => $post["process_id"],
            "parent" => $post["parent_id"],
            "status" => $post["post_status"],
            "template" => $post["template_id"],
            "slug" => $post["replaced_slug"],
            "parent_search_term" => $post["parent_search_term"],
            "permalink" => get_permalink($post["ID"]),
            "page_manually_updated_at" => $post["page_manually_updated_at"]);
        $array = array_merge($array, $this->language_payload((int)$post["ID"]));
        if(isset($post["page_manually_updated_by"])) {
            $WP_User = get_user_by("id", $post["page_manually_updated_by"]);
            if($WP_User) {
                $array["page_manually_updated_by"] = [
                    "name" => $WP_User->display_name,
                    "email" => $WP_User->user_email
                ];
            }

        }
        return $array;
    }

    private function get_google_sheet_data($lpagery_process)
    {
        $data = maybe_unserialize($lpagery_process->google_sheet_data);
        if ($data === null) {
            return [
                "add" => false,
                "update" => false,
                "delete" => false
            ];
        }
        
        // Ensure the data matches the schema
        $validated_data = [
            "add" => isset($data["add"]) ? (bool)$data["add"] : false,
            "update" => isset($data["update"]) ? (bool)$data["update"] : false,
            "delete" => isset($data["delete"]) ? (bool)$data["delete"] : false
        ];
        
        // Only include url if it's a valid URL
        if (isset($data["url"]) && filter_var($data["url"], FILTER_VALIDATE_URL)) {
            $validated_data["url"] = $data["url"];
        }
        
        return $validated_data;
    }

    /**
     * The Page Set's human label as shown in the Manage table's "purpose_with_name": the
     * editor-given purpose, or a synthesized "<Post type> creation set created at <date>" fallback
     * when no purpose was set. Shared by {@see lpagery_map_process_search} and the global Views list
     * so both render an identical label for the same Page Set.
     *
     * @param string|null $purpose The Page Set's raw purpose (may be empty).
     * @param int         $post_id The template post id (drives the post-type fallback label).
     * @param string      $created The Page Set's MySQL creation datetime.
     */
    public static function lpagery_build_purpose_with_name(?string $purpose, int $post_id, string $created): string
    {
        if (empty($purpose)) {
            $post_type = ucfirst(get_post_type($post_id));
            return $post_type . " creation set created at " . date('Y-m-d', strtotime($created));
        }
        return $purpose;
    }

    public function lpagery_map_process($lpagery_process)
    {
        $user = get_user_by("id", $lpagery_process->user_id);
        $phpdate = strtotime($lpagery_process->created);
        $mysqldate = date('Y-m-d', $phpdate);
        if (empty($lpagery_process->purpose)) {
            $post_type = ucfirst(get_post_type($lpagery_process->post_id));
            $purpose_text = $post_type . " Creation by " . $user->display_name . " at " . $mysqldate;
        } else {
            $purpose_text = $lpagery_process->purpose . " by " . $user->display_name;
        }
        [$next_sync,
            $last_sync,
            $status] = $this->get_google_sheet_sync_details($lpagery_process);

        return array("id" => $lpagery_process->id,
            "post_id" => $lpagery_process->post_id,
            "user" => array("name" => $user->display_name,
                "email" => $user->user_email),
            "post_count" => $lpagery_process->count,
            "managing_system" => $lpagery_process->managing_system,
            "display_purpose" => $purpose_text,
            "google_sheet_data" => maybe_unserialize(self::get_google_sheet_data($lpagery_process)),
            "raw_purpose" => $lpagery_process->purpose,
            "google_sheet_sync_error" => $lpagery_process->google_sheet_sync_error,
            "next_google_sheet_sync" => $next_sync ? $next_sync : null,
            "last_google_sheet_sync" => $last_sync ? $last_sync : null,
            "google_sheet_sync_status" => $status,
            "queue_count" => $lpagery_process->queue_count,
            "processed_queue_count" => $lpagery_process->processed_queue_count,
            "include_parent_as_identifier" => filter_var($lpagery_process->include_parent_as_identifier, FILTER_VALIDATE_BOOLEAN),
            "google_sheet_sync_enabled" => filter_var($lpagery_process->google_sheet_sync_enabled,
                FILTER_VALIDATE_BOOLEAN),
            "created" => $mysqldate);
    }

    public function lpagery_map_process_search($lpagery_process)
    {
        $phpdate = strtotime($lpagery_process->created);
        $user = get_user_by("id", $lpagery_process->user_id);

        $mysqldate = date('Y-m-d', $phpdate);
        $post = get_post($lpagery_process->post_id);
        if ($post) {
            $title = $post->post_title;
            if($post->post_status === "trash") {
                $title = "Trashed (" .  $post->post_title . ")";
            }
            $post_array = array("title" => $title,
                "permalink" => get_permalink($post),
                "type" => get_post_type($post),
                "deleted" => $post->post_status === "trash");

            // The Manage row links into the page's own language, not the default one.
            $post_array = array_merge($post_array, $this->language_payload((int)$lpagery_process->post_id));
        } else {
            $post_array = array("title" => "Deleted (ID: " . $lpagery_process->post_id . ")",
                "deleted" => true);
        }

        [$next_sync,
            $last_sync,
            $status] = $this->get_google_sheet_sync_details($lpagery_process);
        $google_sheet_url = null;
        if (isset($lpagery_process->google_sheet_data)) {
            $sheet_data = maybe_unserialize($lpagery_process->google_sheet_data);
            if ($sheet_data && isset($sheet_data["url"]) && filter_var($sheet_data["url"], FILTER_VALIDATE_URL)) {
                $google_sheet_url = $sheet_data["url"];
            }
        }

        $purpose_text = self::lpagery_build_purpose_with_name($lpagery_process->purpose, $lpagery_process->post_id, $lpagery_process->created);

        $configured_render_mode = $this->extract_render_mode($lpagery_process);
        $live_count = (int)($lpagery_process->live_count ?? 0);
        $classic_count = (int)($lpagery_process->classic_count ?? 0);

        return array("id" => $lpagery_process->id,
            "post_id" => $lpagery_process->post_id,
            "user_id" => $lpagery_process->user_id,
            "errored" => $lpagery_process->errored,
            "in_queue" => $lpagery_process->in_queue,
            "managing_system" => $lpagery_process->managing_system,
            "user" => array("name" => $user->display_name,
                "email" => $user->user_email),
            "post_count" => $lpagery_process->count,
            "display_purpose" => $lpagery_process->purpose,
            "purpose_with_name" => $purpose_text,
            "google_sheet_sync_enabled" => filter_var($lpagery_process->google_sheet_sync_enabled,
                FILTER_VALIDATE_BOOLEAN),
            "created" => $mysqldate,
            "next_google_sheet_sync" => $next_sync,
            "last_google_sheet_sync" => $last_sync,
            "google_sheet_sync_status" => $status,
            "google_sheet_url" => $google_sheet_url,
            "render_mode" => $configured_render_mode,
            "observed_render_mode" => $this->derive_observed_render_mode($classic_count, $live_count,
                $configured_render_mode),
            "classic_count" => $classic_count,
            "live_count" => $live_count,
            "reclaimable_size_bytes" => $this->calculate_reclaimable_size_bytes($lpagery_process, $classic_count),
            "queue_count" => (int)($lpagery_process->queue_count ?? 0),
            // Derived from the queue (total minus still-pending rows), not the processed counter —
            // overlapping worker ticks double-count items, so the raw counter overshoots on large
            // runs. Same derivation as BackgroundRunStatusService::get_active_run_statuses().
            "processed_queue_count" => max(0,
                (int)($lpagery_process->queue_count ?? 0) - (int)($lpagery_process->in_queue ?? 0)),
            "post" => $post_array);
    }

    /**
     * The Page Set's OBSERVED Render Mode (issue #240), delegated to {@see ObservedRenderMode} so the
     * Manage table and the Overview's recent list read the same rule.
     *
     * @param int    $classic_count non-trashed pages without a live marker
     * @param int    $live_count    non-trashed pages carrying the live marker
     * @param string $configured    the configured Render Mode, used for the empty set
     */
    private function derive_observed_render_mode(int $classic_count, int $live_count, string $configured): string
    {
        return ObservedRenderMode::derive($classic_count, $live_count, $configured);
    }

    /**
     * The Reclaimable Size ESTIMATE in bytes (issue #240): how much database space switching the set's
     * remaining classic pages to Live Mode would free, as `classic pages × template footprint` — the
     * footprint being what the stub strip deletes per page (see
     * {@see \LPagery\data\repository\PageSetRepository} `template_footprint_subquery`).
     *
     * Null — i.e. "no estimate to show" — when there is nothing left to convert (no classic pages) or
     * when the Template Page is trashed/deleted, so its footprint is unknown. It is an estimate and
     * never a measurement: real pages diverge from the template they were rendered from.
     */
    private function calculate_reclaimable_size_bytes($lpagery_process, int $classic_count): ?int
    {
        $footprint = $lpagery_process->template_footprint_bytes ?? null;
        if ($classic_count === 0 || $footprint === null) {
            return null;
        }
        return $classic_count * (int)$footprint;
    }

    /**
     * The Page Set's configured Render Mode (Phase 7 manage UI). Read from the config blob;
     * absent ⇒ classic, matching the create-flow convention. Drives the "Switch to Live/Classic
     * Mode" menu label.
     */
    private function extract_render_mode($lpagery_process): string
    {
        return ObservedRenderMode::configured($lpagery_process->data ?? null);
    }


    public function lpagery_map_process_update_details($lpagery_process, $data)
    {
        $mapped_process = $this->lpagery_map_process($lpagery_process);
        $mapped_data = array_map(function ($element) {

            $unserialized = maybe_unserialize($element->data);
            if (property_exists($element, "permalink")) {
                $unserialized['permalink'] = ($element->permalink);
            }

            return $unserialized;
        }, $data);

        $unserialized_data = maybe_unserialize($lpagery_process->data);
        if (!array_key_exists("taxonomy_terms", $unserialized_data)) {
            $tag_ids = array_map(function ($tag) {
                $term = get_term_by("name", $tag, "post_tag");
                return $term ? $term->term_id : null;
            }, $unserialized_data["tags"] ?? []);

            // Filter out any null values from non-existent terms
            $cat_ids = array_filter($unserialized_data["categories"] ?? []);
            $tag_ids = array_filter($tag_ids);

            $unserialized_data["taxonomy_terms"] = ["category" => $cat_ids,
                "post_tag" => $tag_ids];
        }
        if (empty($unserialized_data["taxonomy_terms"])) {
            unset($unserialized_data["taxonomy_terms"]);
        }
        return array("process" => $mapped_process,
            "data" => $mapped_data,
            "config_data" => $unserialized_data,
            "google_sheet_sync_enabled" =>  filter_var($lpagery_process->google_sheet_sync_enabled, FILTER_VALIDATE_BOOLEAN),
            "google_sheet_data" => $this->get_google_sheet_data($lpagery_process)
        );
    }

    private function get_google_sheet_sync_details($process)
    {
        $status = $process->google_sheet_sync_status;
        $next_sync = wp_next_scheduled("lpagery_sync_google_sheet");
        $last_sync = strtotime($process->last_google_sheet_sync);
        if (!$last_sync) {
            $last_sync = null;
        }

        $current_time = current_time('U', true);
        $interval = wp_get_schedules()[get_option("lpagery_google_sheet_sync_interval", "hourly")]["interval"];


        $time_difference_next_sync = $next_sync - $current_time;
        $time_difference_last_sync = $current_time - $last_sync;

        if (!$status) {
            $status = "PLANNED";
        }

        // Overdue-schedule overlay, for sheet-sync sets whose last sync is >15 minutes old and whose next
        // sync is already in the past: within the grace threshold the run is simply PLANNED again, beyond
        // it it surfaces as PAST_DUE. Deliberately NOT an early return — a set with sheet sync disabled
        // (e.g. background-only runs) or a future next-sync must still fall through to the shared
        // derivation below, or its status would bypass derive() and the staleness watchdog entirely.
        if ($time_difference_last_sync > 900 && $process->google_sheet_sync_enabled && $time_difference_next_sync < 0) {
            $past_due_threshold = $interval + 900;
            $status = abs($time_difference_next_sync) <= $past_due_threshold ? "PLANNED" : "PAST_DUE";
        }
        global $wpdb;
        $exists_error = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lpagery_sync_queue WHERE error is not null  AND process_id = {$process->id}");
        $exists_pending = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lpagery_sync_queue WHERE error is null  AND process_id = {$process->id}");

        // Staleness watchdog (issue #220 Phase 6): a background run whose worker never runs would sit in
        // an active status forever. The watchdog is consulted unconditionally so derive() is the single
        // gate on the DERIVED status — mirroring the live status endpoint ({@see BackgroundRunStatusService}),
        // which matters when the raw status is already terminal (e.g. FINISHED) yet derive() re-activates it
        // from leftover pending queue rows. is_stalled() short-circuits cheaply on has_active_background_operation,
        // so a finished/planned set pays almost nothing. When it trips, the run surfaces as STALLED
        // ("background tasks aren't running on this site") instead of "Queued…"; Cancel remains the escape
        // hatch. Sheet-sync runs are excluded inside the watchdog, so their status derivation is untouched.
        $stalled = $this->backgroundWorkerReliability->is_stalled((int)$process->id);

        // The terminal-derivation (drained ⇒ FINISHED, leftover errored/pending ⇒ ERROR/WAITING) plus the
        // stalled overlay is the shared background-run derivation (issue #220 Phase 7) — the same logic
        // the live status endpoint uses, so the Manage row and the polled status can never drift.
        $status = BackgroundRunStatus::derive((string)$status, $exists_pending, $exists_error, $stalled);

        return [$next_sync,
            $last_sync,
            $status];

    }

}
