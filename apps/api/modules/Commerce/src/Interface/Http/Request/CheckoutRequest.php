<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pickup' => ['boolean'],
            'shipping_address' => ['required_if:pickup,false', 'nullable', 'array'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:200'],
            'shipping_address.city' => ['required_with:shipping_address', 'string', 'max:100'],
            'shipping_address.state' => ['required_with:shipping_address', 'string', 'max:100'],
            'scheduled_for' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
