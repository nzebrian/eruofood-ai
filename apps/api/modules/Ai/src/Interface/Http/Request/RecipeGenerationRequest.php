<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class RecipeGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dish_name' => ['required', 'string', 'min:2', 'max:160'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:50'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'dietary_preferences' => ['nullable', 'array'],
            'dietary_preferences.*' => ['string', 'max:40'],
            'available_ingredients' => ['nullable', 'array'],
            'available_ingredients.*' => ['string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
