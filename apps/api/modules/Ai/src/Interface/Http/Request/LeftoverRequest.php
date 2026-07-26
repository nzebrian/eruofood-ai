<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class LeftoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*' => ['string', 'max:120'],
            'dietary_preferences' => ['nullable', 'array'],
            'dietary_preferences.*' => ['string', 'max:40'],
            'meal_type' => ['nullable', 'string', 'max:40'],
        ];
    }
}
