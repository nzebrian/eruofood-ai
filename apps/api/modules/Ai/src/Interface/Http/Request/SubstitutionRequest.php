<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class SubstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ingredient' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:120'],
            'dish_context' => ['nullable', 'string', 'max:200'],
            'dietary_preferences' => ['nullable', 'array'],
            'dietary_preferences.*' => ['string', 'max:40'],
        ];
    }
}
