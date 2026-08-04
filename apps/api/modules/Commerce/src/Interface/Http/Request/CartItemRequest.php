<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class CartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
            'variant_sku' => ['nullable', 'string', 'max:60'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
