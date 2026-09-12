<?php

namespace LPagery\multilingual;

/**
 * The single language-related shape LPagery hands to callers for a post.
 *
 * Plugin-neutral: every Multilingual Plugin adapter fills the same four fields, and every
 * one of them is nullable because a post may have no Page Language at all.
 */
class PostLanguageData
{
    public ?string $language_code;

    /** Language-aware permalink, or the plain permalink when the post has no language. */
    public ?string $permalink;

    /** Human-readable language name as the Multilingual Plugin spells it (e.g. "Deutsch"). */
    public ?string $language_name;

    public ?string $flag_url;

    public function __construct(?string $language_code, ?string $permalink, ?string $language_name = null, ?string $flag_url = null)
    {
        $this->language_code = $language_code;
        $this->permalink = $permalink;
        $this->language_name = $language_name;
        $this->flag_url = $flag_url;
    }
}
