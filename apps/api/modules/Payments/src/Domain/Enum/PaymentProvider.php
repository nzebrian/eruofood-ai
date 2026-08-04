<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** The payment providers the platform can route to. */
enum PaymentProvider: string
{
    case Paystack = 'paystack';
    case Flutterwave = 'flutterwave';
    case Moniepoint = 'moniepoint';
    case Stripe = 'stripe';   // architecture-ready
    case Paypal = 'paypal';   // architecture-ready
    case Wallet = 'wallet';   // internal wallet settlement
    case Mock = 'mock';       // deterministic, offline (tests/local)
}
