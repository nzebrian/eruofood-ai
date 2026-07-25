<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * URL-safe slug value object. Generic, reusable across bounded contexts.
 */
final readonly class Slug
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid slug.', $value));
        }

        $this->value = $value;
    }

    /** Build a slug from arbitrary text (lower-case, hyphenated, ASCII). */
    public static function fromTitle(string $title): self
    {
        $ascii = preg_replace('/[^\p{L}\p{N}]+/u', '-', $title) ?? '';
        $lower = strtolower(trim($ascii, '-'));
        $clean = preg_replace('/[^a-z0-9-]/', '', $lower) ?? '';
        $collapsed = preg_replace('/-+/', '-', $clean) ?? '';

        return new self($collapsed === '' ? 'item' : $collapsed);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
