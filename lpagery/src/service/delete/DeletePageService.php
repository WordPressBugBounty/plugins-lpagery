<?php

namespace LPagery\service\delete;

use Exception;
use LPagery\data\repository\GeneratedPageRepository;
use LPagery\service\live_render\LiveTemplateDeleteGuard;

/**
 * LPagery's own bulk page deletion. It removes the rows directly instead of going through
 * `wp_delete_post`, which is what makes deleting thousands of Generated Pages finish in one request,
 * and also means WordPress's own `pre_delete_post` filter never fires. The delete guard of ADR 0017
 * is therefore applied here as well: a page that is the Template Page of a live Page Set (a chained
 * set, where a Generated Page of one set is the template of another) is skipped and reported back,
 * so the caller can tell the user what LPagery kept and why.
 */
class DeletePageService
{
    private GeneratedPageRepository $generatedPageRepository;
    private LiveTemplateDeleteGuard $liveTemplateDeleteGuard;

    public function __construct(GeneratedPageRepository $generatedPageRepository, LiveTemplateDeleteGuard $liveTemplateDeleteGuard)
    {
        $this->generatedPageRepository = $generatedPageRepository;
        $this->liveTemplateDeleteGuard = $liveTemplateDeleteGuard;
    }

    /**
     * Delete every given page in one transaction and return the ids LPagery kept because live pages
     * still render from them. $force skips the guard for a caller whose stated intent is a full wipe
     * (Reset LPagery), where honouring the guard would leave orphan pages behind depending on the
     * order the Page Sets happen to be deleted in.
     *
     * @param array<int|string> $post_ids
     * @return array<int> the ids of the guarded Template Pages that were not deleted
     */
    public function deletePages(array $post_ids, bool $force = false): array
    {
        global $wpdb;
        $skipped = array();
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($post_ids as $post_id) {
                $post_id = (int)$post_id;
                $post = get_post($post_id);
                if(!$post){
                    continue;
                }

                // Asked after the post is known to exist: a template whose row is already gone leaves
                // its live pages orphaned (ADR 0017) and there is nothing left to keep or report.
                if (!$force && $this->liveTemplateDeleteGuard->is_guarded_template($post_id)) {
                    $skipped[] = $post_id;
                    continue;
                }

                do_action('before_delete_post', $post_id, $post);
                
                // Delete revisions
                $wpdb->delete(
                    $wpdb->posts,
                    array('post_parent' => $post_id, 'post_type' => 'revision')
                );
                
                // Delete the main post and related data
                $wpdb->delete($wpdb->posts, array('ID' => $post_id));
                $wpdb->delete($wpdb->postmeta, array('post_id' => $post_id));
                $wpdb->delete($wpdb->term_relationships, array('object_id' => $post_id));
                $this->generatedPageRepository->delete_post($post_id);

                clean_post_cache($post_id);

                do_action('deleted_post', $post_id, $post);
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        // Clear various caches
        wp_cache_flush();

        return $skipped;
    }


}
