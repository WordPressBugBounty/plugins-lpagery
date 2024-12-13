<?php
namespace LPagery\wpml;
class WpmlLanguageData
{
    public function __construct(?string $language_code, ?string $permalink)
    {
        $this->language_code = $language_code;
        $this->permalink = $permalink;
    }
    public ?string $language_code;
    public ?string $permalink;

}