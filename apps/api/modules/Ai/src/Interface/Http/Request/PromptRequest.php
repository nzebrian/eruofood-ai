<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class PromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'feature' => ['required', 'in:recipe_generation,recipe_improvement,recipe_translation,ingredient_substitution,cooking_assistant,meal_suggestions,leftover_recipes,recipe_summarization,cooking_tips,food_description'],
            'name' => ['required', 'string', 'max:160'],
            'system_template' => ['nullable', 'string', 'max:8000'],
            'user_template' => ['required', 'string', 'max:8000'],
            'model' => ['nullable', 'string', 'max:120'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['string', 'max:60'],
            'activate' => ['nullable', 'boolean'],
        ];
    }
}
