<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class DiaryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', 'in:breakfast,lunch,dinner,snack'],
            'servings' => ['required', 'numeric', 'min:0.1', 'max:100'],
            // Either log an existing item or a custom food.
            'nutrition_item_id' => ['required_without:item_name', 'nullable', 'uuid'],
            'item_name' => ['required_without:nutrition_item_id', 'nullable', 'string', 'max:160'],
            'nutrition' => ['nullable', 'array'],
            'nutrition.calories' => ['nullable', 'numeric', 'min:0'],
            'nutrition.protein_grams' => ['nullable', 'numeric', 'min:0'],
            'nutrition.carb_grams' => ['nullable', 'numeric', 'min:0'],
            'nutrition.fat_grams' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
