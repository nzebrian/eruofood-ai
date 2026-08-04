<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\ValueObject;

/**
 * A bag of dynamic variables substituted into a prompt template.
 *
 * Keeps template interpolation type-safe and deterministic: values are
 * flattened to strings on the way in, so the rendered prompt (and therefore the
 * response-cache key) is stable for the same logical input.
 */
final readonly class PromptVariables
{
    /** @param array<string, string> $values */
    private function __construct(public array $values)
    {
    }

    /** @param array<string, scalar|array<mixed>|null> $values */
    public static function fromArray(array $values): self
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $flat[$key] = self::stringify($value);
        }

        return new self($flat);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @param scalar|array<mixed>|null $value */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
