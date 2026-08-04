<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Request;

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
            'menu_item_id' => ['required', 'uuid'],
            'variant_name' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
