<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class NutritionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['nullable', 'string', 'max:40'],
            'serving_size' => ['required', 'array'],
            'serving_size.label' => ['required', 'string', 'max:60'],
            'serving_size.grams' => ['required', 'numeric', 'min:0.1'],
            'nutrition' => ['required', 'array'],
            'nutrition.calories' => ['required', 'numeric', 'min:0'],
            'nutrition.protein_grams' => ['required', 'numeric', 'min:0'],
            'nutrition.carb_grams' => ['required', 'numeric', 'min:0'],
            'nutrition.fat_grams' => ['required', 'numeric', 'min:0'],
            'nutrition.fibre_grams' => ['nullable', 'numeric', 'min:0'],
            'nutrition.sugar_grams' => ['nullable', 'numeric', 'min:0'],
            'nutrition.sodium_mg' => ['nullable', 'numeric', 'min:0'],
            'nutrition.cholesterol_mg' => ['nullable', 'numeric', 'min:0'],
            'nutrition.water_ml' => ['nullable', 'numeric', 'min:0'],
            'nutrition.micronutrients' => ['nullable', 'array'],
            'food_id' => ['nullable', 'uuid'],
        ];
    }
}
