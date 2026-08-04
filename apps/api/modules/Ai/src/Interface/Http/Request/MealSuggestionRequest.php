<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class MealSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'meal_type' => ['nullable', 'string', 'max:40'],
            'dietary_preferences' => ['nullable', 'array'],
            'dietary_preferences.*' => ['string', 'max:40'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'budget' => ['nullable', 'string', 'max:60'],
        ];
    }
}
