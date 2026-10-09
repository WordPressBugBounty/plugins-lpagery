<?php

namespace LPagery\service\preflight;

use LPagery\utils\Utils;

/**
 * Reads the Template Page into a {@see TemplateScan}: its title, content, excerpt and every post
 * meta value the run copies to the pages, page builder data included. LPagery's own meta and the
 * editing locks the run never copies are left out.
 */
class TemplateScanner
{
    public function scan(int $template_id): TemplateScan
    {
        $pieces = [];
        $template = get_post($template_id);
        if ($template) {
            $pieces[] = (string)$template->post_title;
            $pieces[] = (string)$template->post_content;
            $pieces[] = (string)$template->post_excerpt;
        }

        $meta = get_post_meta($template_id);
        $skipped = array_flip(Utils::lpagery_get_default_filtered_meta_names());
        foreach (is_array($meta) ? $meta : [] as $key => $values) {
            if (isset($skipped[$key]) || strpos((string)$key, '_lpagery') === 0) {
                continue;
            }
            foreach ((array)$values as $value) {
                self::collect(maybe_unserialize($value), $pieces);
            }
        }
        return new TemplateScan($pieces);
    }

    /**
     * @param mixed $value
     * @param string[] $pieces
     */
    private static function collect($value, array &$pieces): void
    {
        if (is_array($value) || is_object($value)) {
            foreach ((array)$value as $item) {
                self::collect($item, $pieces);
            }
        } elseif (is_scalar($value)) {
            $pieces[] = (string)$value;
        }
    }
}
