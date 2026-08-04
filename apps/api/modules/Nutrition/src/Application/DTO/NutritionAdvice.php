<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\DTO;

/** AI-generated personalisation advice plus its provenance metadata. */
final readonly class NutritionAdvice
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public bool $cached,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'advice' => $this->text,
            'meta' => [
                'provider' => $this->provider,
                'model' => $this->model,
                'cached' => $this->cached,
            ],
        ];
    }
}
