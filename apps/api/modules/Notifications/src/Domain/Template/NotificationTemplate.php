<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Template;

use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\ValueObject\RenderedContent;

/**
 * A reusable message template keyed by (key, channel, locale). Bodies use simple
 * `{{ placeholder }}` interpolation against a notification's data payload, so
 * copy is centralised and localisable without code changes.
 */
final class NotificationTemplate
{
    private function __construct(
        private readonly string $id,
        private readonly string $key,
        private readonly NotificationChannel $channel,
        private readonly string $locale,
        private string $subject,
        private string $body,
    ) {
    }

    public static function create(
        string $id,
        string $key,
        NotificationChannel $channel,
        string $locale,
        string $subject,
        string $body,
    ): self {
        return new self($id, $key, $channel, $locale, $subject, $body);
    }

    public static function reconstitute(
        string $id,
        string $key,
        NotificationChannel $channel,
        string $locale,
        string $subject,
        string $body,
    ): self {
        return new self($id, $key, $channel, $locale, $subject, $body);
    }

    public function update(string $subject, string $body): void
    {
        $this->subject = $subject;
        $this->body = $body;
    }

    /**
     * Render the template against a data payload ({{ key }} interpolation).
     *
     * @param array<string, mixed> $data
     */
    public function render(array $data): RenderedContent
    {
        return new RenderedContent($this->interpolate($this->subject, $data), $this->interpolate($this->body, $data));
    }

    /** @param array<string, mixed> $data */
    private function interpolate(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $m) use ($data): string {
            $value = $data[$m[1]] ?? '';

            return is_scalar($value) ? (string) $value : '';
        }, $template) ?? $template;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }
}
