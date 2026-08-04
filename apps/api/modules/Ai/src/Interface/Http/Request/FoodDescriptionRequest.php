<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class FoodDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'food_name' => ['required', 'string', 'min:2', 'max:160'],
            'region' => ['nullable', 'string', 'max:60'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string', 'max:40'],
        ];
    }
}
