<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'support_email' => ['nullable', 'email', 'max:150'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'array'],
            'address.line1' => ['required_with:address', 'string', 'max:200'],
            'address.city' => ['required_with:address', 'string', 'max:100'],
            'address.state' => ['required_with:address', 'string', 'max:100'],
        ];
    }
}
