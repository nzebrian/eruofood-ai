<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class MealPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'period' => ['required', 'in:daily,weekly,monthly'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'entries' => ['nullable', 'array'],
            'entries.*.date' => ['required', 'date_format:Y-m-d'],
            'entries.*.meal_type' => ['required', 'in:breakfast,lunch,dinner,snack'],
            'entries.*.label' => ['required', 'string', 'max:160'],
            'entries.*.servings' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'entries.*.nutrition_item_id' => ['nullable', 'uuid'],
            'entries.*.estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
