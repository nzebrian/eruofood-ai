<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class AnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.nutrition_item_id' => ['required', 'uuid'],
            'items.*.servings' => ['required', 'numeric', 'min:0.1', 'max:100'],
        ];
    }
}
