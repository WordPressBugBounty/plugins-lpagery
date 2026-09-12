<?php

namespace LPagery\service\image_endpoint;

/**
 * The Virtual Image Map shape (ADR 0013): the per-page mapping from each copy-form Image column to its
 * resolved source attachment ID + substituted filename, persisted in the reused `attachment_id_pairs`
 * column under the {@see self::KEY} discriminator. Living alongside the legacy `{source, target}` shape
 * (which lacks this key) means a legacy stub still deserializes and renders through the old mapping.
 *
 * Pure shape helpers only — written at generation, read by the render pass and the Image Endpoint.
 */
class VirtualImageMap
{
    public const KEY = 'virtual_images';

    /**
     * @param array<string,mixed> $pairs the deserialized `attachment_id_pairs` value
     */
    public static function is_virtual(array $pairs): bool
    {
        return isset($pairs[self::KEY]) && is_array($pairs[self::KEY]) && !empty($pairs[self::KEY]);
    }

    /**
     * Normalized virtual entries (malformed records skipped), keys dropped — the map is keyed by Image
     * column at write time for sync updates, but consumers only need the (source_id, filename) list.
     *
     * @param array<string,mixed> $pairs
     * @return array<int,array{source_id:int,filename:string}>
     */
    public static function entries(array $pairs): array
    {
        if (!isset($pairs[self::KEY]) || !is_array($pairs[self::KEY])) {
            return array();
        }
        $out = array();
        foreach ($pairs[self::KEY] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $source_id = isset($entry['source_id']) ? (int)$entry['source_id'] : 0;
            $filename = isset($entry['filename']) ? (string)$entry['filename'] : '';
            if ($source_id <= 0 || $filename === '') {
                continue;
            }
            $out[] = array('source_id' => $source_id, 'filename' => $filename);
        }
        return $out;
    }

    /**
     * Wrap the per-column virtual map into the serializable pairs-column shape.
     *
     * @param array<string,array{source_id:int,filename:string}> $map_by_key
     * @return array{virtual_images: array<string,array{source_id:int,filename:string}>}
     */
    public static function build(array $map_by_key): array
    {
        return array(self::KEY => $map_by_key);
    }
}
