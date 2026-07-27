<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'in:grocery,general'],
            'department' => ['nullable', 'in:produce,pantry,beverages,frozen,household,other'],
            'category_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price_minor' => ['required', 'integer', 'min:0'],
            'brand' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:20'],
            'variants' => ['array'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:60'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:80'],
            'variants.*.price_delta_minor' => ['nullable', 'integer'],
            'images' => ['array'],
            'images.*' => ['string', 'max:2048'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:40'],
        ];
    }
}
