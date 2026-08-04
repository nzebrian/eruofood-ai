<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

/**
 * SEO metadata for a CMS content item. An immutable value object — replaced
 * wholesale when content is edited, never mutated in place.
 */
final readonly class SeoMetadata
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public ?string $metaTitle = null,
        public ?string $metaDescription = null,
        public array $keywords = [],
        public ?string $ogImage = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}
