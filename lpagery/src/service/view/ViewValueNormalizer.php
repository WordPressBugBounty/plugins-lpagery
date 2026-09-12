<?php

namespace LPagery\service\view;

/**
 * Single source of truth for how a match value is normalized before comparison.
 *
 * Both sides of every View comparison must use this:
 *  - the resolver normalizes the current page's / explicit match value and each candidate's
 *    value on the live-fallback path;
 *  - the meta-index maintenance stores values normalized the same way, and the index query
 *    compares with exact equality.
 *
 * Keeping it here means the index-backed path and the live-deserialization path stay
 * byte-identical, which is the behavior-invariance guarantee of ADR-0001 / slice #163.
 *
 * Slice #162 makes this case-insensitive + trimmed by changing this one method — and because
 * both paths route through it, they change together. Matching is exact after normalization:
 * "Boston" == "boston" == " Boston ", but "Boston" != "Boston, MA". It is never fuzzy/partial.
 */
class ViewValueNormalizer
{
    public static function normalize(string $value): string
    {
        $trimmed = trim($value);
        if (function_exists("mb_strtolower")) {
            return mb_strtolower($trimmed, "UTF-8");
        }
        return strtolower($trimmed);
    }
}
