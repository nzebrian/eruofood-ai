<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class CalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:500'],
            'height_cm' => ['required', 'numeric', 'min:50', 'max:260'],
            'age' => ['required', 'integer', 'min:1', 'max:120'],
            'gender' => ['required', 'in:male,female,other'],
            'activity_level' => ['nullable', 'in:sedentary,light,moderate,active,very_active'],
            'goal' => ['nullable', 'in:lose_weight,maintain,gain_weight,gain_muscle'],
        ];
    }
}
