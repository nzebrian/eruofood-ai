<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class FoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'category_id' => ['required', 'uuid'],
            'region' => ['required', 'in:north_central,north_east,north_west,south_east,south_south,south_west,nationwide'],
            'description' => ['nullable', 'string', 'max:5000'],
            'states' => ['nullable', 'array'],
            'states.*' => ['string', 'max:60'],
            'local_names' => ['nullable', 'array'],
            'local_names.*.name' => ['required', 'string', 'max:120'],
            'local_names.*.language' => ['required', 'string', 'max:60'],
            'nutrition' => ['nullable', 'array'],
            'nutrition.calories' => ['nullable', 'integer', 'min:0'],
            'nutrition.protein_grams' => ['nullable', 'numeric', 'min:0'],
            'nutrition.carbohydrate_grams' => ['nullable', 'numeric', 'min:0'],
            'nutrition.fat_grams' => ['nullable', 'numeric', 'min:0'],
            'nutrition.fiber_grams' => ['nullable', 'numeric', 'min:0'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ];
    }
}
