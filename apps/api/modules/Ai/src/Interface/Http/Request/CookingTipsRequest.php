<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class CookingTipsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'topic' => ['required', 'string', 'min:2', 'max:160'],
            'skill_level' => ['nullable', 'in:beginner,home cook,intermediate,advanced'],
        ];
    }
}
