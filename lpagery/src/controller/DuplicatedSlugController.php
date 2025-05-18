<?php

namespace LPagery\controller;

use LPagery\factories\DuplicateSlugHandlerFactory;
use LPagery\service\duplicates\DuplicateSlugProvider;
use LPagery\service\duplicates\DuplicateSlugResult;
use LPagery\utils\Utils;

/**
 * Controller for handling duplicated slugs
 */
class DuplicatedSlugController
{
    private static $instance;
    private DuplicateSlugProvider $duplicateSlugHandler;

    /**
     * DuplicatedSlugController constructor.
     *
     * @param DuplicateSlugProvider $duplicateSlugHandler
     */
    public function __construct(DuplicateSlugProvider $duplicateSlugHandler)
    {
        $this->duplicateSlugHandler = $duplicateSlugHandler;
    }

    /**
     * Singleton pattern implementation
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self(DuplicateSlugHandlerFactory::create());
        }
        return self::$instance;
    }

    /**
     * Gets duplicated slugs
     *
     * @param array $data Data array
     * @param int $template_id Template ID
     * @param bool $includeParentAsIdentifier Whether to include parent as identifier
     * @param int $parent_id Parent ID
     * @param string $slug Slug
     * @param int $process_id Process ID
     * @param array $keys Keys
     * @return DuplicateSlugResult Result of operation
     */
    public function getDuplicatedSlugs( $data, int $template_id, bool $includeParentAsIdentifier, int $parent_id, string $slug, int $process_id, array $keys, bool $early_abort): DuplicateSlugResult
    {
        $sanitized_slug = Utils::lpagery_sanitize_title_with_dashes($slug);
        
        return $this->duplicateSlugHandler->lpagery_get_duplicated_slugs(
            $data, 
            $template_id, 
            $includeParentAsIdentifier, 
            $parent_id, 
            $sanitized_slug,
            $process_id, 
            $keys,
            $early_abort
        );
    }
} 