<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
