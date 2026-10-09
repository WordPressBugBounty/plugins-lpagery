<?php

namespace LPagery\service\preflight\check;

use LPagery\service\preflight\Finding;
use LPagery\service\preflight\PreflightContext;
use LPagery\utils\Utils;

/**
 * Page Set Findings about the slug and the Template Page's title: no Placeholder in either, and
 * slug Placeholders that have no column. Slug Placeholders match a column ignoring case and
 * sanitization, the same way the slug is substituted.
 */
class PlaceholderCheck implements PreflightCheck
{
    public function id(): string
    {
        return 'placeholders';
    }

    public function run(PreflightContext $context): array
    {
        $request = $context->request();
        $findings = [];

        if (!$this->contains_placeholder(Utils::lpagery_sanitize_title_with_dashes($request->slug), $request->keys, true)) {
            $findings[] = Finding::pageSet(Finding::PROBLEM, 'slug-without-placeholder', ['slug' => $request->slug]);
        }

        $missing = $this->missing_placeholders($request->slug, $request->keys);
        if (!empty($missing)) {
            $findings[] = Finding::pageSet(Finding::PROBLEM, 'missing-placeholders', [
                'slug' => $request->slug,
                'placeholders' => $missing,
            ]);
        }

        if (!$this->contains_placeholder(strtolower($context->template_title()), $request->keys, false)) {
            $findings[] = Finding::pageSet(Finding::PROBLEM, 'title-without-placeholder');
        }

        return $findings;
    }

    /**
     * @param string[] $keys
     */
    private function contains_placeholder(string $value, array $keys, bool $sanitize): bool
    {
        foreach ($keys as $key) {
            $placeholder = '{' . trim($key, '{}') . '}';
            $placeholder = $sanitize ? Utils::lpagery_sanitize_title_with_dashes($placeholder) : strtolower($placeholder);
            if (strpos($value, $placeholder) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $keys
     * @return string[]
     */
    private function missing_placeholders(string $slug, array $keys): array
    {
        preg_match_all('/{([^}]+)}/', $slug, $matches);
        $columns = array_map(function ($key) {
            return strtolower(Utils::lpagery_sanitize_title_with_dashes($key));
        }, $keys);

        return array_values(array_filter($matches[1], function ($placeholder) use ($columns) {
            return !in_array(strtolower($placeholder), $columns, true);
        }));
    }
}
