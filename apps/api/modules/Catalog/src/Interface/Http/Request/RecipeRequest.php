<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class RecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'food_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'prep_time_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'cook_time_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
            'serving_size' => ['required', 'integer', 'min:1', 'max:100'],

            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.name' => ['required', 'string', 'max:180'],
            'ingredients.*.amount' => ['required', 'numeric', 'min:0'],
            'ingredients.*.unit' => ['required', 'in:g,kg,ml,l,cup,tbsp,tsp,piece,pinch,handful,wrap,to_taste'],
            'ingredients.*.ingredient_id' => ['nullable', 'uuid'],
            'ingredients.*.note' => ['nullable', 'string', 'max:255'],

            'steps' => ['required', 'array', 'min:1'],
            'steps.*.order' => ['required', 'integer', 'min:1'],
            'steps.*.instruction' => ['required', 'string', 'max:2000'],
            'steps.*.duration_minutes' => ['nullable', 'integer', 'min:0'],

            'tips' => ['nullable', 'array'],
            'tips.*' => ['string', 'max:500'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ];
    }
}
