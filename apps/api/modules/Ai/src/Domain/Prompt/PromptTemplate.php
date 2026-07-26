<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Prompt;

use DateTimeImmutable;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A versioned, testable prompt template — the unit of the Prompt Management
 * System.
 *
 * A template belongs to exactly one {@see AiFeature} and carries a `system` and
 * `user` body containing `{{ variable }}` placeholders. Many versions may exist
 * for a feature; exactly one is marked active and served at runtime. Older
 * versions are retained so prompts can be A/B compared and rolled back — the
 * prompt is treated as source code, not a magic string buried in a service.
 */
final class PromptTemplate
{
    /**
     * @param list<string> $variables names the template expects (for validation/testing)
     */
    private function __construct(
        private readonly string $id,
        private readonly AiFeature $feature,
        private readonly int $version,
        private string $name,
        private string $systemTemplate,
        private string $userTemplate,
        private ?string $model,
        private array $variables,
        private bool $active,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<string> $variables
     */
    public static function create(
        string $id,
        AiFeature $feature,
        int $version,
        string $name,
        string $systemTemplate,
        string $userTemplate,
        ?string $model,
        array $variables,
        bool $active,
        DateTimeImmutable $createdAt,
    ): self {
        if ($version < 1) {
            throw new InvalidArgumentException('Prompt version must be >= 1.');
        }
        if (trim($userTemplate) === '') {
            throw new InvalidArgumentException('A prompt template needs a user body.');
        }

        return new self(
            $id,
            $feature,
            $version,
            $name,
            $systemTemplate,
            $userTemplate,
            $model,
            array_values(array_map('strval', $variables)),
            $active,
            $createdAt,
        );
    }

    /** Render the template with concrete values, leaving unknown tokens blank. */
    public function render(PromptVariables $vars): RenderedPrompt
    {
        return new RenderedPrompt(
            self::interpolate($this->systemTemplate, $vars),
            self::interpolate($this->userTemplate, $vars),
        );
    }

    private static function interpolate(string $template, PromptVariables $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static fn (array $m): string => $vars->get($m[1]) ?? '',
            $template,
        );
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function feature(): AiFeature
    {
        return $this->feature;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function systemTemplate(): string
    {
        return $this->systemTemplate;
    }

    public function userTemplate(): string
    {
        return $this->userTemplate;
    }

    /** Optional "provider/model" pin, e.g. "anthropic/claude-opus-5". */
    public function model(): ?string
    {
        return $this->model;
    }

    /** @return list<string> */
    public function variables(): array
    {
        return $this->variables;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
