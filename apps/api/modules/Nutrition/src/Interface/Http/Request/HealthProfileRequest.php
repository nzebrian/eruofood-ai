<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class HealthProfileRequest extends FormRequest
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
            'activity_level' => ['required', 'in:sedentary,light,moderate,active,very_active'],
            'goal' => ['required', 'in:lose_weight,maintain,gain_weight,gain_muscle'],
            'dietary_preferences' => ['nullable', 'array'],
            'dietary_preferences.*' => ['string', 'max:40'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:40'],
            'medical_restrictions' => ['nullable', 'array'],
            'medical_restrictions.*' => ['string', 'max:60'],
        ];
    }
}
