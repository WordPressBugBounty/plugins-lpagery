<?php

namespace LPagery\service\substitution;

use DOMDocument;
use LPagery\model\Params;

class ImageSubstitutionHandler
{
    private static ?ImageSubstitutionHandler $instance = null;

    public function __construct()
    {
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }


    public function replace_images_from_html($content, Params $params)
    {
        $source_attachment_ids = $params->source_attachment_ids ?? array();
        $target_attachment_ids = $params->target_attachment_ids ?? array();
        $keys = $params->image_keys ?? array();
        $values = $params->image_values ?? array();
        $dom = new DomDocument();
        $utf8_added = false;

        $content_to_replace = $content;
        if (!str_starts_with($content_to_replace, "<?xml encoding")) {
            $content_to_replace = '<?xml encoding="utf-8" ?>' . $content_to_replace;
            $utf8_added = true;
        }
        libxml_use_internal_errors(true);
        $dom->loadHTML($content_to_replace, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        $images = $dom->getElementsByTagName("img");
        $image_replaced = false;
        foreach ($images as $image) {
            $src_set = false;
            $prev_source = $image->getAttribute("src");
            if ($prev_source) {
                $index_src = array_search($prev_source, $keys);
                if ($index_src !== false) {
                    $image->setAttribute("src", $values[$index_src]);
                    $src_set = true;
                }
            }

            $attachment_postid = $this->find_post_id_from_path($prev_source);
            $source_index = array_search($attachment_postid, $source_attachment_ids);
            if (is_numeric($source_index)) {
                $target_attachment_id = $target_attachment_ids[$source_index];
                if ($target_attachment_id) {
                    $new_attachment_url = wp_get_attachment_url($target_attachment_id);
                    if (!$src_set) {
                        $image->setAttribute("src", $new_attachment_url);
                    }

                    $image_alt = get_post_meta($target_attachment_id, '_wp_attachment_image_alt', true);
                    $image->setAttribute("alt", $image_alt);

                    $title = get_the_title($target_attachment_id);
                    $image->setAttribute("title", $title);

                    if ($image->getAttribute("srcset")) {
                        $image->setAttribute("srcset", wp_get_attachment_image_srcset($target_attachment_id));
                    }

                    if ($image->getAttribute("data-img-src")) {
                        $image->setAttribute("data-img-src", $new_attachment_url);
                    }
                    if ($image->getAttribute("data-attachment-id")) {
                        $image->setAttribute("data-attachment-id", $target_attachment_id);
                    }
                    if ($image->getAttribute("sizes")) {
                        $image->setAttribute("sizes", wp_get_attachment_image_sizes($target_attachment_id, "large"));
                    }
                    $image_replaced = true;
                }
            }
        }

        if(!$image_replaced) {
            return $content;
        }
        $saved = $dom->saveHTML();
        if ($utf8_added) {
            $saved = str_replace('<?xml encoding="utf-8" ?>', '', $saved);
        }
        return ($saved);
    }

    private function find_post_id_from_path($path)
    {
        if (substr($path, 0, 4) !== "http") {
            $path = strstr($path, '/');
            $path = trim($path, '/');
        }
        // detect if is a media resize, and strip resize portion of file name
        if (preg_match('/(-\d{1,4}x\d{1,4})\.(jpg|jpeg|png|gif)$/i', $path, $matches)) {
            $path = str_ireplace($matches[1], '', $path);
        }

        // process and include the year / month folders so WP function below finds properly
        if (preg_match('/uploads\/(\d{1,4}\/)?(\d{1,2}\/)?(.+)$/i', $path, $matches)) {
            unset($matches[0]);
            $path = implode('', $matches);
        }

        // at this point, $path contains the year/month/file name (without resize info)

        // call WP native function to find post ID properly
        return attachment_url_to_postid($path);
    }

}
