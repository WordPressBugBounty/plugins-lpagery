<?php

namespace LPagery\io\hooks;

use LPagery\service\image_lookup\AttachmentBasenameService;
use LPagery\service\settings\SettingsController;

class AttachmentIndexHooks
{
    public static function register(): void
    {
        add_filter('attachment_fields_to_edit', [self::class, 'add_media_fields'], 10, 2);

        // Keep basename lookup table in sync for fast attachment searches
        // Note: add_attachment fires BEFORE _wp_attached_file metadata is set, so we use hooks that fire after

        // Hook into attachment metadata generation (fires after _wp_attached_file is set for new uploads)
        add_filter('wp_generate_attachment_metadata', [self::class, 'on_attachment_metadata_generated'], 10, 2);

        // Hook into attachment metadata updates
        add_filter('wp_update_attachment_metadata', [self::class, 'on_attachment_metadata_updated'], 10, 2);

        // Hook into post meta addition (catches _wp_attached_file being set)
        add_action('added_post_meta', [self::class, 'on_attachment_file_meta_added'], 10, 4);

        // Hook into post meta updates (catches _wp_attached_file being updated)
        add_action('updated_post_meta', [self::class, 'on_attachment_file_meta_updated'], 10, 4);

        // Hook into attachment edits
        add_action('edit_attachment', [self::class, 'on_edit_attachment']);

        // Hook into attachment updates (fires when attachment post is updated)
        add_action('attachment_updated', [self::class, 'on_attachment_updated'], 10, 3);

        // Hook into REST API attachment creation/updates
        add_action('rest_after_insert_attachment', [self::class, 'on_rest_attachment_insert'], 10, 3);

        // Clean up basename lookup table when attachment is deleted
        add_action('delete_attachment', [self::class, 'delete_attachment_basename']);

        // Delete guard (ADR 0014): purge the live pages whose Virtual Image Maps reference the deleted
        // attachment so their cached HTML stops pointing at a file that no longer exists. Never blocks
        // the deletion. Free/serve-path safety — not premium-gated.
        add_action('delete_attachment', [self::class, 'purge_live_pages_for_deleted_attachment']);

        // Daily cron job to backfill the attachment basename lookup table
        add_action('lpagery_backfill_attachment_basename', [self::class, 'backfill_attachment_basename']);

        add_filter('attachment_fields_to_save', [self::class, 'save_replace_filename_field'], 10, 2);

        // Schedule the daily backfill cron if not already scheduled
        if (!wp_next_scheduled('lpagery_backfill_attachment_basename')) {
            wp_schedule_event(time(), 'daily', 'lpagery_backfill_attachment_basename');
        }
    }

    public static function add_media_fields($form_fields, $post)
    {
        $settingsController = lpagery_root()->settingsController();
        if ($settingsController->isImageProcessingEnabled()) {
            $form_fields['lpagery_replace_filename'] = array('label' => '<img width="25px" height ="25px" src="' . plugin_dir_url(dirname(__FILE__, 4)) . "/" . plugin_basename(dirname(__FILE__, 4)) . '/assets/lpagery.png"/>Download Filename',
                'input' => 'text',
                'value' => get_post_meta($post->ID, '_lpagery_replace_filename', true),
                'helps' => 'The name for LPagery to be taken for downloading images when using this image as an placeholder. The ending will be populated automatically. Please add placeholders from the input file here (e.g. "my-image-in-{city}")',);

            $form_fields['lpagery_update_metadata'] = array(
                'label' => '<img width="25px" height="25px" src="' . plugin_dir_url(dirname(__FILE__, 4)) . "/" . plugin_basename(dirname(__FILE__, 4)) . '/assets/lpagery.png"/>Update Metadata',
                'input' => 'html',
                'html' => '<input type="checkbox" name="attachments[' . $post->ID . '][lpagery_update_metadata]" value="1" ' . checked(get_post_meta($post->ID, '_lpagery_update_metadata', true), '1', false) . ' />',
                'helps' => 'Check this box to update the image metadata of existing replacement images when processing this image'
            );
        }

        // Delete-guard blast-radius line (ADR 0014): how many Live Mode pages' Virtual Image Maps
        // reference this attachment. Free/serve-path safety — shown regardless of license state, so it
        // sits outside the image-processing gate. No line when nothing references it.
        $referencing_count = lpagery_root()->attachmentDeleteGuard()->count_referencing_live_pages((int)$post->ID);
        if ($referencing_count > 0) {
            $form_fields['lpagery_live_usage'] = array(
                'label' => '<img width="25px" height="25px" src="' . plugin_dir_url(dirname(__FILE__, 4)) . "/" . plugin_basename(dirname(__FILE__, 4)) . '/assets/lpagery.png"/>Live pages',
                'input' => 'html',
                'html' => '<p>' . esc_html(sprintf(
                    /* translators: %d: number of LPagery Live Mode pages using this image */
                    _n('Used by %d LPagery live page', 'Used by %d LPagery live pages', $referencing_count, 'lpagery'),
                    $referencing_count
                )) . '</p>',
                'helps' => 'You can still delete this image, but these Live Mode pages will then show a broken image. LPagery clears their cache when you delete it.'
            );
        }

        return $form_fields;
    }

    public static function on_attachment_metadata_generated($metadata, $attachment_id) {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file) {
            lpagery_root()->attachmentBasenameService()->insert($attachment_id, $file);
        }
        return $metadata;
    }

    public static function on_attachment_metadata_updated($metadata, $attachment_id) {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file) {
            lpagery_root()->attachmentBasenameService()->insert($attachment_id, $file);
        }
        return $metadata;
    }

    public static function on_attachment_file_meta_added($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_wp_attached_file' && $meta_value) {
            lpagery_root()->attachmentBasenameService()->insert($post_id, $meta_value);
        }
    }

    public static function on_attachment_file_meta_updated($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_wp_attached_file' && $meta_value) {
            lpagery_root()->attachmentBasenameService()->insert($post_id, $meta_value);
        }
    }

    public static function on_edit_attachment($attachment_id) {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file) {
            lpagery_root()->attachmentBasenameService()->insert($attachment_id, $file);
        }
    }

    public static function on_attachment_updated($post_id, $post_after, $post_before) {
        $file = get_post_meta($post_id, '_wp_attached_file', true);
        if ($file) {
            lpagery_root()->attachmentBasenameService()->insert($post_id, $file);
        }
    }

    public static function on_rest_attachment_insert($attachment, $request, $creating) {
        $file = get_post_meta($attachment->ID, '_wp_attached_file', true);
        if ($file) {
            lpagery_root()->attachmentBasenameService()->insert($attachment->ID, $file);
        }
    }

    public static function delete_attachment_basename($attachment_id) {
        lpagery_root()->attachmentBasenameService()->delete($attachment_id);
    }

    public static function purge_live_pages_for_deleted_attachment($attachment_id) {
        lpagery_root()->attachmentDeleteGuard()->purge_pages_referencing((int)$attachment_id);
    }

    public static function backfill_attachment_basename() {
        lpagery_root()->attachmentBasenameService()->backfill();
    }

    public static function save_replace_filename_field($post, $attachment)
    {
        if (isset($attachment['lpagery_replace_filename'])) {
            // Update or add the custom field value
            update_post_meta($post['ID'], '_lpagery_replace_filename', $attachment['lpagery_replace_filename']);
        }

        if (isset($attachment['lpagery_update_metadata'])) {
            update_post_meta($post['ID'], '_lpagery_update_metadata', '1');
        } else {
            update_post_meta($post['ID'], '_lpagery_update_metadata', '0');
        }

        return $post;
    }
}
