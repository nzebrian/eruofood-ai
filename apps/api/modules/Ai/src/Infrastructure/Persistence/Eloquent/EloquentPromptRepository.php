<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Prompt\PromptRepository;
use EruoFood\Ai\Domain\Prompt\PromptTemplate;
use EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model\PromptTemplateModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Eloquent-backed {@see PromptRepository}. */
final class EloquentPromptRepository implements PromptRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function activeForFeature(AiFeature $feature): ?PromptTemplate
    {
        $model = PromptTemplateModel::query()
            ->where('feature', $feature->value)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findById(string $id): ?PromptTemplate
    {
        $model = PromptTemplateModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function versionsForFeature(AiFeature $feature): array
    {
        return array_values(array_map(
            fn (PromptTemplateModel $m): PromptTemplate => $this->toDomain($m),
            PromptTemplateModel::query()
                ->where('feature', $feature->value)
                ->orderByDesc('version')
                ->get()
                ->all(),
        ));
    }

    public function latestVersion(AiFeature $feature): int
    {
        return (int) PromptTemplateModel::query()
            ->where('feature', $feature->value)
            ->max('version');
    }

    public function save(PromptTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            // Enforce a single active version per feature.
            if ($template->isActive()) {
                PromptTemplateModel::query()
                    ->where('feature', $template->feature()->value)
                    ->where('id', '!=', $template->id())
                    ->update(['active' => false]);
            }

            $model = PromptTemplateModel::query()->find($template->id()) ?? new PromptTemplateModel();
            $model->id = $template->id();
            $model->feature = $template->feature()->value;
            $model->version = $template->version();
            $model->name = $template->name();
            $model->system_template = $template->systemTemplate();
            $model->user_template = $template->userTemplate();
            $model->model = $template->model();
            $model->variables = $template->variables();
            $model->active = $template->isActive();
            $model->created_at = $template->createdAt();
            $model->save();
        });
    }

    private function toDomain(PromptTemplateModel $m): PromptTemplate
    {
        return PromptTemplate::create(
            id: $m->id,
            feature: AiFeature::from($m->feature),
            version: $m->version,
            name: $m->name,
            systemTemplate: $m->system_template,
            userTemplate: $m->user_template,
            model: $m->model,
            variables: $m->variables ?? [],
            active: $m->active,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
