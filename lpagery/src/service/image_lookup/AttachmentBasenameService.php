<?php

namespace LPagery\service\image_lookup;

class AttachmentBasenameService
{
    private static ?AttachmentBasenameService $instance = null;

    public static function get_instance(): AttachmentBasenameService
    {
        if (self::$instance === null) {
            self::$instance = new AttachmentBasenameService();
        }
        return self::$instance;
    }

    /**
     * Get the basename lookup table name
     */
    public function get_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'lpagery_attachment_basename';
    }

    /**
     * Insert or update an attachment in the basename lookup table
     */
    public function insert(int $attachment_id, string $filepath): void
    {
        global $wpdb;
        $basename = basename($filepath);
        $basename_no_ext = pathinfo($basename, PATHINFO_FILENAME);

        $wpdb->replace(
            $this->get_table_name(),
            [
                'attachment_id' => $attachment_id,
                'basename' => $basename,
                'basename_no_ext' => $basename_no_ext
            ],
            ['%d', '%s', '%s']
        );
    }

    /**
     * Delete an attachment from the basename lookup table
     */
    public function delete(int $attachment_id): void
    {
        global $wpdb;
        $wpdb->delete(
            $this->get_table_name(),
            ['attachment_id' => $attachment_id],
            ['%d']
        );
    }

    /**
     * Search for attachments by exact basename (with extension)
     * 
     * @return array Array of objects with ID property
     */
    public function search_by_basename(string $basename): array
    {
        global $wpdb;
        $table = $this->get_table_name();

        $query = $wpdb->prepare(
            "SELECT ab.attachment_id as ID
             FROM {$table} ab
             INNER JOIN {$wpdb->posts} p ON p.ID = ab.attachment_id
             WHERE p.post_type = 'attachment'
             AND p.post_status IN ('inherit','private')
             AND ab.basename = %s
             LIMIT 20",
            $basename
        );

        return $wpdb->get_results($query) ?: [];
    }

    /**
     * Search for attachments by basename without extension
     * 
     * @return array Array of objects with ID property
     */
    public function search_by_basename_no_ext(string $basename_no_ext): array
    {
        global $wpdb;
        $table = $this->get_table_name();

        $query = $wpdb->prepare(
            "SELECT ab.attachment_id as ID
             FROM {$table} ab
             INNER JOIN {$wpdb->posts} p ON p.ID = ab.attachment_id
             WHERE p.post_type = 'attachment'
             AND p.post_status IN ('inherit','private')
             AND ab.basename_no_ext = %s
             LIMIT 20",
            $basename_no_ext
        );

        return $wpdb->get_results($query) ?: [];
    }

    /**
     * Search for attachments - automatically chooses the right column based on whether
     * the search term has an extension
     * 
     * @return array Array of objects with ID property
     */
    public function search(string $name): array
    {
        $search_basename = basename(urldecode($name));
        $has_extension = pathinfo($search_basename, PATHINFO_EXTENSION) !== '';

        if ($has_extension) {
            return $this->search_by_basename($search_basename);
        } else {
            return $this->search_by_basename_no_ext($search_basename);
        }
    }

    /**
     * Search for attachments with multiple filename variations.
     * Tries: exact basename, basename_no_ext, jpg/jpeg equivalents, and -scaled removal.
     * 
     * @return array Array of objects with ID property
     */
    public function search_with_variations(string $name): array
    {
        $variations = $this->get_search_variations($name);
        
        foreach ($variations as $variation) {
            // Try exact basename match first
            $results = $this->search_by_basename($variation['basename']);
            if (!empty($results)) {
                return $results;
            }
            
            // Try basename without extension
            $results = $this->search_by_basename_no_ext($variation['basename_no_ext']);
            if (!empty($results)) {
                return $results;
            }
        }
        
        return [];
    }

    /**
     * Generate all search variations for a filename.
     * Includes: original, jpg/jpeg swapped, -scaled removed, and combinations.
     * 
     * @return array Array of variations, each with 'basename' and 'basename_no_ext' keys
     */
    private function get_search_variations(string $name): array
    {
        $search_basename = basename(urldecode($name));
        $variations = [];
        $seen = [];
        
        // Helper to add a variation if not already seen
        $addVariation = function(string $basename) use (&$variations, &$seen) {
            $basename_no_ext = pathinfo($basename, PATHINFO_FILENAME);
            $key = strtolower($basename . '|' . $basename_no_ext);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $variations[] = [
                    'basename' => $basename,
                    'basename_no_ext' => $basename_no_ext
                ];
            }
        };
        
        // Get all base variations (original, jpg/jpeg swapped, -scaled removed, combined)
        $base_variations = $this->get_base_variations($search_basename);
        
        foreach ($base_variations as $basename) {
            $addVariation($basename);
        }
        
        return $variations;
    }

    /**
     * Generate base filename variations (jpg/jpeg swap and -scaled removal).
     * 
     * @return array Array of filename strings
     */
    private function get_base_variations(string $basename): array
    {
        $variations = [$basename];
        
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $filename = pathinfo($basename, PATHINFO_FILENAME);
        
        // Handle jpg <-> jpeg equivalence
        $jpeg_variant = null;
        if ($extension === 'jpg') {
            $jpeg_variant = $filename . '.jpeg';
            $variations[] = $jpeg_variant;
        } elseif ($extension === 'jpeg') {
            $jpeg_variant = $filename . '.jpg';
            $variations[] = $jpeg_variant;
        }
        
        // Handle -scaled removal
        if (str_ends_with(strtolower($filename), '-scaled')) {
            $filename_no_scaled = substr($filename, 0, -7); // Remove '-scaled'
            
            // Add without -scaled (with original extension)
            if ($extension) {
                $no_scaled_variant = $filename_no_scaled . '.' . $extension;
                $variations[] = $no_scaled_variant;
                
                // Also add jpg/jpeg variant without -scaled
                if ($extension === 'jpg') {
                    $variations[] = $filename_no_scaled . '.jpeg';
                } elseif ($extension === 'jpeg') {
                    $variations[] = $filename_no_scaled . '.jpg';
                }
            } else {
                $variations[] = $filename_no_scaled;
            }
        }
        
        return array_unique($variations);
    }

    /**
     * Backfill the basename lookup table from all existing attachments.
     * Uses INSERT IGNORE to avoid duplicates.
     * 
     * @return int Number of rows inserted
     */
    public function backfill(): int
    {
        global $wpdb;
        $table = $this->get_table_name();

        // Use a subquery to extract basename first, then compute basename_no_ext
        // by extracting text before the LAST dot (matching PHP's pathinfo behavior).
        // For files like "image.backup.jpg", this returns "image.backup" not "image".
        $wpdb->query("
            INSERT IGNORE INTO {$table} (attachment_id, basename, basename_no_ext)
            SELECT 
                attachment_id,
                basename,
                IF(
                    LOCATE('.', basename) = 0,
                    basename,
                    SUBSTRING_INDEX(basename, '.', CHAR_LENGTH(basename) - CHAR_LENGTH(REPLACE(basename, '.', '')))
                ) as basename_no_ext
            FROM (
                SELECT 
                    pm.post_id as attachment_id,
                    SUBSTRING_INDEX(pm.meta_value, '/', -1) as basename
                FROM {$wpdb->postmeta} pm
                WHERE pm.meta_key = '_wp_attached_file'
            ) as derived
        ");

        return $wpdb->rows_affected;
    }
}

