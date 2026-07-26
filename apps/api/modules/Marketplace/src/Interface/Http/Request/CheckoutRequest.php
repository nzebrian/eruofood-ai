<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Request;

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
            'fulfilment' => ['required', 'in:delivery,pickup'],
            'delivery_address' => ['required_if:fulfilment,delivery', 'nullable', 'array'],
            'delivery_address.line' => ['required_with:delivery_address', 'string', 'max:200'],
            'delivery_address.city' => ['required_with:delivery_address', 'string', 'max:80'],
            'delivery_address.state' => ['required_with:delivery_address', 'string', 'max:80'],
            'delivery_address.location' => ['nullable', 'array'],
            'delivery_address.location.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_address.location.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'scheduled_for' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
