<?php

namespace LPagery\service\duplicates;

use JsonSerializable;

class DuplicateSlugResult implements JsonSerializable {
    private bool $slugContainsPlaceholder;
    private array $duplicates;
    private array $existingSlugs;
    private array $numericSlugs;
    private bool $titleContainsPlaceholder;
    private array $attachmentSlugEquals;
    private bool $foundDuplicatedSlugsWithDifferentParents;
    private array $missingPlaceholders;

    public function __construct(
        bool $slugContainsPlaceholder,
        array $duplicates,
        array $existingSlugs,
        array $numericSlugs,
        bool $titleContainsPlaceholder,
        array $attachmentSlugEquals,
        bool $foundDuplicatedSlugsWithDifferentParents,
        array $missingPlaceholders = []
    ) {
        $this->slugContainsPlaceholder = $slugContainsPlaceholder;
        $this->duplicates = $duplicates;
        $this->existingSlugs = $existingSlugs;
        $this->numericSlugs = $numericSlugs;
        $this->titleContainsPlaceholder = $titleContainsPlaceholder;
        $this->attachmentSlugEquals = $attachmentSlugEquals;
        $this->missingPlaceholders = $missingPlaceholders;
        $this->foundDuplicatedSlugsWithDifferentParents = $foundDuplicatedSlugsWithDifferentParents;
    }

    public function jsonSerialize(): array {
        return [
            'slug_contains_placeholder' => $this->slugContainsPlaceholder,
            'duplicates' => $this->duplicates,
            'existing_slugs' => $this->existingSlugs,
            'numeric_slugs' => $this->numericSlugs,
            'title_contains_placeholder' => $this->titleContainsPlaceholder,
            'attachment_slug_equals' => $this->attachmentSlugEquals,
            'missing_placeholders' => $this->missingPlaceholders,
            'found_duplicated_slugs_with_different_parents' => $this->foundDuplicatedSlugsWithDifferentParents
        ];
    }

    public function getSlugContainsPlaceholder(): bool {
        return $this->slugContainsPlaceholder;
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

    public function getFoundDuplicatedSlugsWithDifferentParents(): bool {
        return $this->foundDuplicatedSlugsWithDifferentParents;
    }
} 