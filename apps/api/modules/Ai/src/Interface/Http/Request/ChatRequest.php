<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'conversation_id' => ['nullable', 'uuid'],
        ];
    }
}
