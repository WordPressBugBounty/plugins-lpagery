<?php

namespace LPagery\service\duplicates;

use JsonSerializable;

class DuplicateSlugResult implements JsonSerializable {
    private bool $allSlugsAreTheSame;
    private array $duplicates;
    private array $existingSlugs;
    private array $numericSlugs;
    private bool $titleContainsPlaceholder;
    private array $attachmentSlugEquals;
    private array $missingPlaceholders;

    public function __construct(
        bool $allSlugsAreTheSame,
        array $duplicates,
        array $existingSlugs,
        array $numericSlugs,
        bool $titleContainsPlaceholder,
        array $attachmentSlugEquals,
        array $missingPlaceholders = []
    ) {
        $this->allSlugsAreTheSame = $allSlugsAreTheSame;
        $this->duplicates = $duplicates;
        $this->existingSlugs = $existingSlugs;
        $this->numericSlugs = $numericSlugs;
        $this->titleContainsPlaceholder = $titleContainsPlaceholder;
        $this->attachmentSlugEquals = $attachmentSlugEquals;
        $this->missingPlaceholders = $missingPlaceholders;
    }

    public function jsonSerialize(): array {
        return [
            'all_slugs_are_the_same' => $this->allSlugsAreTheSame,
            'duplicates' => $this->duplicates,
            'existing_slugs' => $this->existingSlugs,
            'numeric_slugs' => $this->numericSlugs,
            'title_contains_placeholder' => $this->titleContainsPlaceholder,
            'attachment_slug_equals' => $this->attachmentSlugEquals,
            'missing_placeholders' => $this->missingPlaceholders
        ];
    }

    public function getAllSlugsAreTheSame(): bool {
        return $this->allSlugsAreTheSame;
    }

    public function getDuplicates(): array {
        return $this->duplicates;
    }

    public function getExistingSlugs(): array {
        return $this->existingSlugs;
    }

    public function getNumericSlugs(): array {
        return $this->numericSlugs;
    }

    public function getTitleContainsPlaceholder(): bool {
        return $this->titleContainsPlaceholder;
    }

    public function getAttachmentSlugEquals(): array {
        return $this->attachmentSlugEquals;
    }

    public function getMissingPlaceholders(): array {
        return $this->missingPlaceholders;
    }
} 