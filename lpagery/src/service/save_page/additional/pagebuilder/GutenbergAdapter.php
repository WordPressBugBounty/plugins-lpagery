<?php

namespace LPagery\service\save_page\additional\pagebuilder;

use LPagery\model\Params;
use LPagery\service\Beautify_Html;
use WP_Post;

class GutenbergAdapter implements PagebuilderAdapter
{
    public function supports(WP_Post $template_post, Params $params): bool
    {
        return in_array("{lpagery_content}", $params->keys);
    }

    public function apply(int $template_post_id, int $generated_page_id, Params $params): void
    {
        $this->handle_gutenberg($generated_page_id);
    }

    private function handle_gutenberg($targetPostId)
    {
        $post = get_post($targetPostId);
        $post_content = $post->post_content;

        if (!has_blocks($post_content) || str_contains($post_content, 'wp:kadence')) {
            return;
        }
        $formatted = self::lpagery_do_blocks($post_content);

        // Update the post content using wp_update_post
        wp_update_post(array('ID' => $targetPostId,
            'post_content' => $formatted));
    }

    private function lpagery_do_blocks($content)
    {
        $blocks = parse_blocks($content);
        $serialized = self::serialize_blocks($blocks);

        return $serialized;
    }

    private function serialize_blocks($blocks)
    {
        return implode("\r\n", array_map([$this, 'serialize_block'], $blocks));
    }

    private function serialize_block($block)
    {
        $block_content = '';

        $index = 0;
        foreach ($block['innerContent'] as $chunk) {
            $block_content .= is_string($chunk) ? $chunk : serialize_block($block['innerBlocks'][$index++]);
        }

        if (!is_array($block['attrs'])) {
            $block['attrs'] = array();
        }


        $beautify = new Beautify_Html(array('indent_inner_html' => false,
            'indent_char' => " ",
            'indent_size' => 2,
            'wrap_line_length' => 9999999999,
            'unformatted' => [],
            'preserve_newlines' => false,
            'max_preserve_newlines' => 9999999999,
            'indent_scripts' => 'normal'
            // keep|separate|normal
        ));
        $block_content = $beautify->beautify($block_content, $block['blockName']);


        return self::get_comment_delimited_block_content($block['blockName'], $block['attrs'], $block_content);
    }

    private function get_comment_delimited_block_content($block_name, $block_attributes, $block_content)
    {
        if (is_null($block_name)) {
            return $block_content;
        }

        $serialized_block_name = strip_core_block_namespace($block_name);
        $serialized_attributes = empty($block_attributes) ? '' : serialize_block_attributes($block_attributes) . ' ';

        if (empty($block_content)) {
            return sprintf("\r\n<!-- wp:%s\r\n %s/-->\r\n", $serialized_block_name, $serialized_attributes);
        }

        return sprintf("\r\n<!-- wp:%s %s-->\r\n%s\r\n<!-- /wp:%s -->\r\n", $serialized_block_name,
            $serialized_attributes, $block_content, $serialized_block_name);
    }
}
