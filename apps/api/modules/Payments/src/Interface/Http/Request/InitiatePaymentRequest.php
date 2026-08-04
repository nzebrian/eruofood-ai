<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'customer_email' => ['required', 'email', 'max:150'],
            'order_id' => ['nullable', 'uuid'],
            'method_type' => ['nullable', 'in:card,bank_transfer,wallet,qr,ussd'],
            'provider' => ['nullable', 'in:paystack,flutterwave,moniepoint,stripe,paypal,wallet,mock'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'splits' => ['array'],
            'splits.*.payee_type' => ['required_with:splits', 'in:vendor,driver,platform'],
            'splits.*.payee_id' => ['required_with:splits', 'uuid'],
            'splits.*.amount_minor' => ['required_with:splits', 'integer', 'min:0'],
        ];
    }
}
