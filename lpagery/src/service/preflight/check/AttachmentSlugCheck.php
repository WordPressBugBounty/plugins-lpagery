<?php

namespace LPagery\service\preflight\check;

use LPagery\data\repository\GeneratedPageRepository;
use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;

/**
 * Rows whose slug a media-library item already uses. WordPress adds a number to such a page's URL.
 */
class AttachmentSlugCheck implements PreflightCheck
{
    private GeneratedPageRepository $generatedPageRepository;

    public function __construct(GeneratedPageRepository $generatedPageRepository)
    {
        $this->generatedPageRepository = $generatedPageRepository;
    }

    public function id(): string
    {
        return 'attachment-slugs';
    }

    public function run(PreflightContext $context): array
    {
        $row_ids_by_slug = [];
        foreach ($context->rows() as $row) {
            if ($row->slug !== '') {
                $row_ids_by_slug[$row->slug][] = $row->row_id;
            }
        }
        if (empty($row_ids_by_slug)) {
            return [];
        }

        $row_ids = [];
        $examples = [];
        foreach ($this->generatedPageRepository->find_attachments_by_slugs(array_map('strval', array_keys($row_ids_by_slug))) as $attachment) {
            $slug_row_ids = $row_ids_by_slug[$attachment->post_name] ?? [];
            if (empty($slug_row_ids)) {
                continue;
            }
            $row_ids = array_merge($row_ids, $slug_row_ids);
            if (count($examples) < Finding::MAX_EXAMPLES) {
                $examples[] = [
                    'slug' => (string)$attachment->post_name,
                    'permalink' => (string)admin_url('upload.php?item=' . (int)$attachment->id),
                    'row_ids' => $slug_row_ids,
                ];
            }
        }
        if (empty($row_ids)) {
            return [];
        }
        $row_ids = array_values(array_unique($row_ids));
        sort($row_ids);
        return [Finding::rows(Finding::PROBLEM, 'attachment-slugs', $row_ids, $examples)];
    }
}
