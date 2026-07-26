<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class ProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:500'],
            'note' => ['nullable', 'string', 'max:280'],
        ];
    }
}
