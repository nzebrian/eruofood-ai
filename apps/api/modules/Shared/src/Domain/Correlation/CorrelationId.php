<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Correlation;

use Illuminate\Support\Str;

/**
 * The thread that ties one customer action to everything it caused.
 *
 * Customer → order → payment → merchant → dispatch → rider → delivery →
 * settlement is eight contexts and at least as many database rows. When one of
 * them misbehaves at 2am, the question is always "what else was part of this?",
 * and without a shared id the answer is reconstructed by hand from timestamps.
 *
 * ## Trusted and untrusted ids are not the same thing
 *
 * A caller may send `X-Request-Id`, and honouring it is genuinely useful: a
 * mobile client, a load balancer or an upstream service can then find its own
 * request in our logs. But an id a caller chose is an id a caller controls, and
 * two things follow:
 *
 * - It is fine for **tracing**. Worst case somebody makes their own logs hard
 *   to read, or collides with another request and confuses themselves.
 * - It is **not** fine for **audit**. An attacker who can pick the correlation
 *   id on a regulated-data read can point that audit record at somebody else's
 *   request, or reuse an id to make two accesses look like one.
 *
 * So this type carries both: an `external` id echoed back for the caller's
 * benefit, and an `internal` id this platform generated and nobody else can
 * influence. Audit trails and the financial ledger take the internal one. See
 * {@see CorrelationContext::forAudit()}.
 *
 * ## Shape
 *
 * Inbound ids are constrained to something log-safe and bounded. An unbounded
 * header lands in every log line for the request, and a header containing
 * newlines lets a caller forge log entries.
 */
final readonly class CorrelationId
{
    /** Long enough for a UUID or a W3C trace id, short enough to be harmless. */
    private const MAX_LENGTH = 128;

    private function __construct(
        public string $internal,
        public ?string $external,
    ) {
    }

    /** A fresh id for work that did not arrive over HTTP — a console command, a test. */
    public static function generate(): self
    {
        return new self((string) Str::uuid(), null);
    }

    /**
     * An id for an inbound request, honouring the caller's header when usable.
     *
     * A rejected header is not an error. The request proceeds with a
     * server-generated id, because failing a request over a malformed
     * *diagnostic* header would turn an observability feature into an outage.
     */
    public static function fromInbound(?string $header): self
    {
        $external = self::sanitise($header);

        return new self((string) Str::uuid(), $external);
    }

    /** Restore a correlation carried across a queue or event boundary. */
    public static function restore(string $internal, ?string $external): self
    {
        return new self($internal, self::sanitise($external) ?? null);
    }

    /**
     * What the caller should see, which is their own id when they supplied one.
     *
     * Echoing the caller's id back is the entire point of honouring it — a
     * client that gets a different id back cannot correlate anything.
     */
    public function forResponse(): string
    {
        return $this->external ?? $this->internal;
    }

    /**
     * The value safe to write into an audit record or the financial ledger.
     *
     * Always the internal id. Never the caller's.
     */
    public function forAudit(): string
    {
        return $this->internal;
    }

    /** @return array{correlation_id: string, external_correlation_id: string|null} */
    public function toLogContext(): array
    {
        return [
            'correlation_id' => $this->internal,
            'external_correlation_id' => $this->external,
        ];
    }

    /**
     * Accept only what is safe to put in a log line, or nothing.
     *
     * Control characters are the reason this exists: a header containing a
     * newline can inject a fabricated line into a log stream.
     */
    private static function sanitise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_LENGTH) {
            return null;
        }

        // Printable ASCII without whitespace: covers UUIDs, W3C traceparent,
        // and the opaque ids proxies generate, and excludes everything else.
        return preg_match('/^[A-Za-z0-9._:\-]+$/', $trimmed) === 1 ? $trimmed : null;
    }
}
