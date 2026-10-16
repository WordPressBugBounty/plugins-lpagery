<?php

namespace LPagery\service\lookup;

use LPagery\service\image_lookup\AttachmentBasenameService;

/**
 * The image side of the App Connection lookups: the media picker and the batch resolve of image
 * values in the Reference form, the file name a run looks an image up by.
 */
class MediaLookup
{
    private AttachmentBasenameService $attachmentBasenameService;

    public function __construct(AttachmentBasenameService $attachmentBasenameService)
    {
        $this->attachmentBasenameService = $attachmentBasenameService;
    }

    /**
     * The media library's images whose title or file name matches the search, newest first. Only
     * images in the basename index are listed, since a run finds an image by its file name there,
     * so each item's `basename` is a Reference-form value that finds it.
     *
     * @return array{items: array<int, array{id: int, basename: string, title: string, thumbnail_url: string}>, has_more: bool}
     */
    public function search(string $search, int $page, int $per_page): array
    {
        global $wpdb;
        $table = $this->attachmentBasenameService->get_table_name();
        $args = [];
        $search_condition = '';
        if ($search !== '') {
            $search_condition = 'AND (p.post_title LIKE %s OR ab.basename LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($args, $like, $like);
        }
        // One row past the page tells whether another page follows.
        array_push($args, $per_page + 1, ($page - 1) * $per_page);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, ab.basename FROM $wpdb->posts p
             INNER JOIN $table ab ON ab.attachment_id = p.ID
             WHERE p.post_type = 'attachment'
             AND p.post_status IN ('inherit','private')
             AND p.post_mime_type LIKE 'image/%%'
             $search_condition
             ORDER BY p.post_date DESC, p.ID DESC
             LIMIT %d OFFSET %d",
            $args
        )) ?: [];

        $items = array_map(function ($row) {
            return [
                'id' => (int)$row->ID,
                'basename' => (string)$row->basename,
                'title' => (string)$row->post_title,
                'thumbnail_url' => (string)wp_get_attachment_image_url((int)$row->ID, 'thumbnail'),
            ];
        }, array_slice($rows, 0, $per_page));
        return ['items' => $items, 'has_more' => count($rows) > $per_page];
    }

    /**
     * The image each value finds the way a run finds a Reference-form value
     * ({@see AttachmentBasenameService::find_by_names()}), labelled by its title or else its file
     * name. A URL is a Download-form value, which a run downloads instead of looking it up, so it
     * finds nothing. One query for the whole set.
     *
     * @param string[] $values
     * @return array<string, array{found: true, id: int, label: string, thumbnail_url: string}>
     */
    public function resolve(array $values): array
    {
        $references = array_values(array_filter($values, function ($value) {
            return !filter_var(trim($value), FILTER_VALIDATE_URL);
        }));
        return array_map(function ($attachment) {
            return [
                'found' => true,
                'id' => $attachment->id,
                'label' => $attachment->title !== '' ? $attachment->title : $attachment->basename,
                'thumbnail_url' => (string)wp_get_attachment_image_url($attachment->id, 'thumbnail'),
            ];
        }, $this->attachmentBasenameService->find_by_names($references));
    }
}
