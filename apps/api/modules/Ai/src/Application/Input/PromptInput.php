<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

use EruoFood\Ai\Domain\Enum\AiFeature;

/** Validated input for creating a new prompt template version. */
final readonly class PromptInput
{
    /** @param list<string> $variables */
    public function __construct(
        public AiFeature $feature,
        public string $name,
        public string $systemTemplate,
        public string $userTemplate,
        public ?string $model,
        public array $variables,
        public bool $activate,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            feature: AiFeature::from((string) $data['feature']),
            name: (string) $data['name'],
            systemTemplate: (string) ($data['system_template'] ?? ''),
            userTemplate: (string) $data['user_template'],
            model: isset($data['model']) && $data['model'] !== '' ? (string) $data['model'] : null,
            variables: array_values(array_map('strval', $data['variables'] ?? [])),
            activate: (bool) ($data['activate'] ?? true),
        );
    }
}
