<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\DTO;

/**
 * The inbound headers an adapter may consult when verifying a callback.
 *
 * Passed as a value object rather than a framework Request so the adapter — and
 * its tests — never depend on HTTP plumbing.
 */
final readonly class WebhookHeaders
{
    /** @param array<string, string> $headers case-insensitive on lookup */
    public function __construct(private array $headers)
    {
    }

    /** @param array<string, list<string|null>|string|null> $raw */
    public static function fromArray(array $raw): self
    {
        $normalised = [];
        foreach ($raw as $key => $value) {
            $normalised[strtolower((string) $key)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) ($value ?? '');
        }

        return new self($normalised);
    }

    public function get(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return ($value === null || $value === '') ? null : $value;
    }
}
