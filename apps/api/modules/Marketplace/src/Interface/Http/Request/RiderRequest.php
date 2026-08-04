<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class RiderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'vehicle_type' => ['required', 'in:bicycle,motorbike,car,foot'],
        ];
    }
}
