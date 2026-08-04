<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class VendorRequest extends FormRequest
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
            'type' => ['required', 'in:restaurant,market_vendor,home_kitchen,cloud_kitchen'],
            'category' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contact' => ['required', 'array'],
            'contact.phone' => ['required', 'string', 'max:30'],
            'contact.email' => ['nullable', 'email', 'max:160'],
            'contact.whatsapp' => ['nullable', 'string', 'max:30'],
            'address' => ['required', 'array'],
            'address.line' => ['required', 'string', 'max:200'],
            'address.city' => ['required', 'string', 'max:80'],
            'address.state' => ['required', 'string', 'max:80'],
            'address.location' => ['nullable', 'array'],
            'address.location.latitude' => ['required_with:address.location', 'numeric', 'between:-90,90'],
            'address.location.longitude' => ['required_with:address.location', 'numeric', 'between:-180,180'],
        ];
    }
}
