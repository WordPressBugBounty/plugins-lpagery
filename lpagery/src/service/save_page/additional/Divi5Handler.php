<?php

namespace LPagery\service\save_page\additional;

use ET\Builder\FrontEnd\BlockParser\BlockParser;
use ET\Builder\Migration\MigrationContext;
use LPagery\model\Params;
use LPagery\service\substitution\SubstitutionHandler;

/**
 * Handler for Divi 5 page builder content.
 *
 * Divi 5 uses Gutenberg blocks with the 'wp:divi/' namespace.
 * This handler parses blocks, runs substitution, processes images, and serializes back.
 */
class Divi5Handler
{
    private static ?Divi5Handler $instance = null;
    private SubstitutionHandler $substitutionHandler;

    private function __construct(SubstitutionHandler $substitutionHandler)
    {
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance(SubstitutionHandler $substitutionHandler): Divi5Handler
    {
        if (null === self::$instance) {
            self::$instance = new self($substitutionHandler);
        }
        return self::$instance;
    }

    /**
     * Detect if content contains Divi 5 blocks.
     * Divi 5 uses Gutenberg blocks with the 'wp:divi/' namespace.
     *
     * @param string $content The post content to check
     * @return bool True if content contains Divi 5 blocks
     */
    public function is_divi5_content(string $content): bool
    {
        return str_contains($content, 'wp:divi/');
    }

    /**
     * Handle Divi 5 content by parsing blocks, processing, and serializing.
     *
     * @param int $sourcePostId The source/template post ID
     * @param int $targetPostId The target post ID
     * @param Params $params The substitution parameters
     * @return void
     */
    public function handle(int $sourcePostId, int $targetPostId, Params $params): void
    {
        $source_post = get_post($sourcePostId);
        if (!$source_post) {
            return;
        }

        $post_content = $source_post->post_content;

        // Check if Divi 5 classes are available
        $has_divi_parser = class_exists('ET\Builder\FrontEnd\BlockParser\BlockParser');
        $has_migration_context = class_exists('ET\Builder\Migration\MigrationContext');
        $use_divi_parser = $has_divi_parser && $has_migration_context;

        if ($use_divi_parser) {
            // Parse blocks to get structured access
            // Use MigrationContext to prevent Divi's BlockParser from expanding
            // divi/global-layout blocks. This preserves the global module structure in generated pages.
            MigrationContext::start();
            try {
                // Use Divi's BlockParser for proper Divi 5 block handling
                $parser = new BlockParser();
                $blocks = $parser->parse($post_content);

                // Process all blocks: substitution + image processing
                $processed_blocks = $this->process_blocks_recursive($blocks, $params);

                // Serialize and save within MigrationContext to prevent Divi's security
                // filters from expanding global layouts during wp_update_post().
                $final_content = serialize_blocks($processed_blocks);

                // Save the processed content
                // wp_slash() is required to preserve backslashes in Unicode escapes (e.g. \u003c)
                // because wp_update_post() calls wp_unslash() internally
                wp_update_post(array(
                    'ID' => $targetPostId,
                    'post_content' => wp_slash($final_content)
                ));
            } finally {
                MigrationContext::end();
            }
        } else {
            // Fallback to WordPress's native parse_blocks() if Divi 5 classes are not available
            $blocks = parse_blocks($post_content);
            $processed_blocks = $this->process_blocks_recursive($blocks, $params);

            $final_content = serialize_blocks($processed_blocks);

            // Save the processed content
            // wp_slash() is required to preserve backslashes in Unicode escapes (e.g. \u003c)
            // because wp_update_post() calls wp_unslash() internally
            wp_update_post(array(
                'ID' => $targetPostId,
                'post_content' => wp_slash($final_content)
            ));
        }
    }

    /**
     * Process Divi 5 images using WordPress block parsing.
     *
     * @param string $content The content to process
     * @param Params $params The substitution parameters with attachment mappings
     * @return string The content with images processed
     */
    public function process_images(string $content, Params $params): string
    {
        $source_attachment_ids = $params->source_attachment_ids ?? array();
        $target_attachment_ids = $params->target_attachment_ids ?? array();

        if (empty($source_attachment_ids) || empty($target_attachment_ids)) {
            return $content;
        }

        // Check if Divi 5 classes are available
        $has_divi_parser = class_exists('ET\Builder\FrontEnd\BlockParser\BlockParser');
        $has_migration_context = class_exists('ET\Builder\Migration\MigrationContext');
        $use_divi_parser = $has_divi_parser && $has_migration_context;

        if ($use_divi_parser) {
            // Parse blocks to get structured access to attributes
            // Use MigrationContext to prevent Divi's BlockParser from expanding
            // divi/global-layout blocks. This preserves the global module structure.
            MigrationContext::start();
            try {
                // Use Divi's BlockParser for proper Divi 5 block handling
                $parser = new BlockParser();
                $blocks = $parser->parse($content);

                // Process all blocks recursively (image processing only, no substitution)
                $processed_blocks = $this->process_blocks_for_images($blocks, $source_attachment_ids, $target_attachment_ids);
            } finally {
                MigrationContext::end();
            }
        } else {
            // Fallback to WordPress's native parse_blocks() if Divi 5 classes are not available
            $blocks = parse_blocks($content);
            $processed_blocks = $this->process_blocks_for_images($blocks, $source_attachment_ids, $target_attachment_ids);
        }

        // Serialize back to content
        return serialize_blocks($processed_blocks);
    }

    /**
     * Build a URL mapping from source attachment URLs to target attachment URLs.
     *
     * @param array $source_attachment_ids Source attachment IDs
     * @param array $target_attachment_ids Target attachment IDs
     * @return array Mapping of source URL => target URL
     */
    private function build_url_mapping(array $source_attachment_ids, array $target_attachment_ids): array
    {
        $url_mapping = array();
        foreach ($source_attachment_ids as $index => $source_id) {
            $source_url = wp_get_attachment_url($source_id);
            $target_id = $target_attachment_ids[$index] ?? null;
            if ($source_url && $target_id) {
                $target_url = wp_get_attachment_url($target_id);
                if ($target_url) {
                    $url_mapping[$source_url] = $target_url;
                }
            }
        }
        return $url_mapping;
    }

    /**
     * Recursively process blocks: run substitution on attributes and process images.
     *
     * @param array $blocks The blocks to process
     * @param Params $params The substitution parameters
     * @return array The processed blocks
     */
    private function process_blocks_recursive(array $blocks, Params $params): array
    {
        $source_attachment_ids = $params->source_attachment_ids ?? array();
        $target_attachment_ids = $params->target_attachment_ids ?? array();
        $should_process_images = $params->image_processing_enabled && !empty($source_attachment_ids) && !empty($target_attachment_ids);

        // Build URL mapping for background images (which don't have IDs)
        $url_mapping = $should_process_images ? $this->build_url_mapping($source_attachment_ids, $target_attachment_ids) : array();

        foreach ($blocks as &$block) {
            // Process inner blocks recursively
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->process_blocks_recursive($block['innerBlocks'], $params);
            }

            // Run substitution on block attributes
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                $block['attrs'] = $this->substitute_in_attrs($block['attrs'], $params);
            }

            // Run substitution on innerHTML
            if (!empty($block['innerHTML'])) {
                $block['innerHTML'] = $this->substitutionHandler->lpagery_substitute($params, $block['innerHTML']);
            }

            // Run substitution on innerContent array
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as &$content) {
                    if (is_string($content)) {
                        $content = $this->substitutionHandler->lpagery_substitute($params, $content);
                    }
                }
            }

            // Process images if enabled
            if ($should_process_images) {
                // Check if this is a Divi image block
                if (isset($block['blockName']) && $block['blockName'] === 'divi/image') {
                    $block = $this->process_image_block($block, $source_attachment_ids, $target_attachment_ids);
                }

                // Also check attrs recursively for image data in other block types (including background images)
                if (isset($block['attrs']) && is_array($block['attrs'])) {
                    $block['attrs'] = $this->process_image_attrs_recursive($block['attrs'], $source_attachment_ids, $target_attachment_ids, $url_mapping);
                }
            }
        }

        return $blocks;
    }

    /**
     * Recursively process blocks for image processing only (used by process_images method).
     *
     * @param array $blocks The blocks to process
     * @param array $source_attachment_ids Source attachment IDs
     * @param array $target_attachment_ids Target attachment IDs
     * @param array|null $url_mapping Optional URL mapping (built once at top level)
     * @return array The processed blocks
     */
    private function process_blocks_for_images(array $blocks, array $source_attachment_ids, array $target_attachment_ids, ?array $url_mapping = null): array
    {
        // Build URL mapping once at top level if not provided
        if ($url_mapping === null) {
            $url_mapping = $this->build_url_mapping($source_attachment_ids, $target_attachment_ids);
        }

        foreach ($blocks as &$block) {
            // Process inner blocks recursively
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->process_blocks_for_images(
                    $block['innerBlocks'],
                    $source_attachment_ids,
                    $target_attachment_ids,
                    $url_mapping
                );
            }

            // Check if this is a Divi image block
            if (isset($block['blockName']) && $block['blockName'] === 'divi/image') {
                $block = $this->process_image_block($block, $source_attachment_ids, $target_attachment_ids);
            }

            // Also check attrs recursively for image data in other block types (including background images)
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                $block['attrs'] = $this->process_image_attrs_recursive($block['attrs'], $source_attachment_ids, $target_attachment_ids, $url_mapping);
            }
        }

        return $blocks;
    }

    /**
     * Recursively run substitution on block attributes.
     *
     * @param array $attrs The attributes to process
     * @param Params $params The substitution parameters
     * @return array The processed attributes
     */
    private function substitute_in_attrs(array $attrs, Params $params): array
    {
        foreach ($attrs as $key => &$value) {
            if (is_string($value)) {
                $value = $this->substitutionHandler->lpagery_substitute($params, $value);
            } elseif (is_array($value)) {
                $value = $this->substitute_in_attrs($value, $params);
            }
        }
        return $attrs;
    }

    /**
     * Process a Divi 5 image block to update src, id, alt, titleText.
     *
     * Divi 5 stores image data in multiple locations:
     * - image.innerContent.desktop.value.{src, id, alt, titleText}
     * - module.decoration.attributes.attributes[] (array of {name, value} objects)
     * - module.decoration.attributes.desktop.value.attributes[] (same structure)
     *
     * @param array $block The image block
     * @param array $source_attachment_ids Source attachment IDs
     * @param array $target_attachment_ids Target attachment IDs
     * @return array The processed block
     */
    private function process_image_block(array $block, array $source_attachment_ids, array $target_attachment_ids): array
    {
        // Divi 5 image structure: attrs.image.innerContent.desktop.value.{src,id,alt,titleText}
        if (!isset($block['attrs']['image']['innerContent']['desktop']['value'])) {
            return $block;
        }

        $value = &$block['attrs']['image']['innerContent']['desktop']['value'];

        if (!isset($value['id'])) {
            return $block;
        }

        $source_id = is_string($value['id']) ? (int) $value['id'] : $value['id'];
        $source_index = array_search($source_id, $source_attachment_ids);

        if ($source_index === false) {
            return $block;
        }

        $target_id = $target_attachment_ids[$source_index] ?? null;

        if (!$target_id) {
            return $block;
        }

        // Get new values from target attachment
        $new_src = wp_get_attachment_url($target_id);
        $new_alt = get_post_meta($target_id, '_wp_attachment_image_alt', true) ?: '';
        $new_title = get_the_title($target_id) ?: '';

        if ($new_src) {
            // Update the main image value object
            $value['src'] = $new_src;
            $value['id'] = $target_id;
            $value['alt'] = $new_alt;
            $value['titleText'] = $new_title;

            // Also update module.decoration.attributes.attributes[] if present
            if (isset($block['attrs']['module']['decoration']['attributes']['attributes'])) {
                $block['attrs']['module']['decoration']['attributes']['attributes'] = 
                    $this->update_divi5_attributes_array(
                        $block['attrs']['module']['decoration']['attributes']['attributes'],
                        $new_alt,
                        $new_title
                    );
            }

            // Also update module.decoration.attributes.desktop.value.attributes[] if present
            if (isset($block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'])) {
                $block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'] = 
                    $this->update_divi5_attributes_array(
                        $block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'],
                        $new_alt,
                        $new_title
                    );
            }
        }

        return $block;
    }

    /**
     * Update Divi 5 attributes array with new alt and title values.
     *
     * Divi 5 stores attributes as an array of objects like:
     * [{"name":"alt","value":"..."},{"name":"title","value":"..."}]
     *
     * @param array $attributes The attributes array
     * @param string $new_alt The new alt text
     * @param string $new_title The new title text
     * @return array The updated attributes array
     */
    private function update_divi5_attributes_array(array $attributes, string $new_alt, string $new_title): array
    {
        foreach ($attributes as &$attr) {
            if (isset($attr['name']) && isset($attr['value'])) {
                if ($attr['name'] === 'alt') {
                    $attr['value'] = $new_alt;
                } elseif ($attr['name'] === 'title') {
                    $attr['value'] = $new_title;
                }
            }
        }
        return $attributes;
    }

    /**
     * Recursively search and process image attributes in any nested structure.
     *
     * Handles two types of image references:
     * 1. divi/image blocks: {src, id, alt, titleText} - matched by ID
     * 2. Background images: {url} - matched by URL
     *
     * @param array $attrs The attributes to process
     * @param array $source_attachment_ids Source attachment IDs
     * @param array $target_attachment_ids Target attachment IDs
     * @param array $url_mapping Mapping of source URLs to target URLs
     * @return array The processed attributes
     */
    private function process_image_attrs_recursive(array $attrs, array $source_attachment_ids, array $target_attachment_ids, array $url_mapping): array
    {
        foreach ($attrs as $key => &$value) {
            if (is_array($value)) {
                // Check if this looks like a Divi 5 image value object (has src, id, alt, titleText)
                if (isset($value['src']) && isset($value['id']) && array_key_exists('alt', $value) && array_key_exists('titleText', $value)) {
                    $source_id = is_string($value['id']) ? (int) $value['id'] : $value['id'];
                    $source_index = array_search($source_id, $source_attachment_ids);

                    if ($source_index !== false) {
                        $target_id = $target_attachment_ids[$source_index] ?? null;

                        if ($target_id) {
                            $new_src = wp_get_attachment_url($target_id);
                            if ($new_src) {
                                $value['src'] = $new_src;
                                $value['id'] = $target_id;
                                $value['alt'] = get_post_meta($target_id, '_wp_attachment_image_alt', true) ?: '';
                                $value['titleText'] = get_the_title($target_id) ?: '';
                            }
                        }
                    }
                }
                // Check if this looks like a Divi 5 background image object (has url key only, no id)
                // Structure: module.decoration.background.desktop.value.image.url
                elseif ($key === 'image' && isset($value['url']) && is_string($value['url']) && !isset($value['id'])) {
                    $source_url = $value['url'];
                    $target_url = $url_mapping[$source_url] ?? null;
                    if ($target_url) {
                        $value['url'] = $target_url;
                    }
                } else {
                    // Recurse into nested arrays
                    $value = $this->process_image_attrs_recursive($value, $source_attachment_ids, $target_attachment_ids, $url_mapping);
                }
            }
        }

        return $attrs;
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function reset_instance(): void
    {
        self::$instance = null;
    }
}
