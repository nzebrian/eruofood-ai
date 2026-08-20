<?php

declare(strict_types=1);

namespace EruoFood\Payments\Contracts;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * An order that another bounded context considers financially final.
 *
 * Carries identity and nothing else — deliberately no amounts. The context that
 * fulfils an order knows *that* it is done and *who* did it; it does not know
 * what the platform captured, what commission applied, or what fees were taken,
 * and a contract that let it say so would be a contract that let it be wrong.
 *
 * Payments derives every figure from the payment it already holds. That is the
 * whole of the F1 fix: the amount is not passed in, from anywhere, by anyone.
 */
final readonly class SettledOrder
{
    public function __construct(
        public string $orderId,
        /** vendor|restaurant|driver — the payee vocabulary Payments already uses. */
        public string $merchantType,
        public string $merchantId,
    ) {
        foreach (['orderId' => $orderId, 'merchantType' => $merchantType, 'merchantId' => $merchantId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("SettledOrder needs a {$field}.");
            }
        }
    }
}
