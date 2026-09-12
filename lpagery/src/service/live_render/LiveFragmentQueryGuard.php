<?php

namespace LPagery\service\live_render;

/**
 * Pure decision seam for the render-fragment cache's query-string rule (ADR 0008): whether a request's
 * query string still allows the one fragment cached per Template Page to be read and written.
 *
 * The fragment is the builder's pre-substitution output for the Template Page, so a parameter that only
 * tags the visit (a campaign or click id) never changes it, while pagination or filter parameters can.
 * A request is therefore cacheable when *every* query key is a known tracking parameter, and one unknown
 * key is enough to bypass. Only keys are inspected; values are irrelevant.
 */
class LiveFragmentQueryGuard
{
    /** Fixed prefix rule: every `utm_*` key is a tracking parameter, including future variants. */
    private const TRACKING_PREFIX = 'utm_';

    /** Exact-match tracking keys, filterable through `lpagery_live_fragment_ignored_query_params`. */
    private const DEFAULT_IGNORED_PARAMS = array('fbclid', 'gclid', 'msclkid', 'ref');

    /**
     * Whether every key of the request's query string is an ignorable tracking parameter, so the
     * fragment stays readable and writable for the request.
     *
     * @param array<string|int,mixed> $query the request's query parameters (`$_GET`); only keys are read
     */
    public static function is_cacheable_query(array $query): bool
    {
        if ($query === array()) {
            return true;
        }
        $ignored = self::ignored_params();
        foreach ($query as $key => $value) {
            $key = (string)$key;
            // Case-sensitive throughout, matching how WordPress and analytics tools treat these keys.
            if (strpos($key, self::TRACKING_PREFIX) === 0 || in_array($key, $ignored, true)) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * The exact-match tracking keys the fragment cache ignores.
     *
     * Filter `lpagery_live_fragment_ignored_query_params` receives and returns this array of keys. The
     * `utm_` prefix rule is fixed and is not part of the filtered array. Note that `ref` is in the
     * default list: a site that uses `ref` for real routing — a referral widget or an affiliate plugin
     * that varies the rendered content — should remove it through this filter.
     *
     * @return array<int,string>
     */
    private static function ignored_params(): array
    {
        $filtered = apply_filters('lpagery_live_fragment_ignored_query_params', self::DEFAULT_IGNORED_PARAMS);
        if (!is_array($filtered)) {
            return self::DEFAULT_IGNORED_PARAMS;
        }
        $keys = array();
        foreach ($filtered as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }
}
