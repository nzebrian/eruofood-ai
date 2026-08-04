<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class RecipeImprovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*' => ['string', 'max:200'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['string', 'max:2000'],
            'goal' => ['nullable', 'string', 'max:200'],
        ];
    }
}
