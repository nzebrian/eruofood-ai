<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class MenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price_minor' => ['required', 'integer', 'min:0'],
            'variants' => ['nullable', 'array'],
            'variants.*.name' => ['required', 'string', 'max:80'],
            'variants.*.price_delta_minor' => ['required', 'integer'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:500'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'track_inventory' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'calories' => ['nullable', 'integer', 'min:0'],
            'nutrition_item_id' => ['nullable', 'uuid'],
        ];
    }
}
