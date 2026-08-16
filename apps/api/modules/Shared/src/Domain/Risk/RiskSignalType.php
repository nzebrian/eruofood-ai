<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Risk;

/**
 * The kinds of abuse the platform will eventually need to detect.
 *
 * Declared now, detected later. M29's Trust Engine is a milestone of its own and
 * nothing here attempts it — but the *seam* has to exist first, because
 * retrofitting signal emission into checkout, dispatch and payments after the
 * fact means touching every one of those flows a second time.
 *
 * Each case names a real pattern rather than an abstract severity, so the
 * eventual detector inherits a vocabulary somebody has already thought about.
 */
enum RiskSignalType: string
{
    /** Accounts created in bulk, often sharing a device or payment instrument. */
    case FakeAccount = 'fake_account';

    /** Stolen cards, forced chargebacks, deliberate payment failure. */
    case PaymentAbuse = 'payment_abuse';

    /** Coupons redeemed beyond their intent — multi-accounting, sharing, scripting. */
    case CouponAbuse = 'coupon_abuse';

    /** Orders placed with no intention of paying for or receiving them. */
    case FakeOrder = 'fake_order';

    /** A rider and a customer, or a rider and a merchant, cooperating against the platform. */
    case RiderCollusion = 'rider_collusion';

    /** A merchant gaming rankings, ratings or settlement. */
    case MerchantManipulation = 'merchant_manipulation';

    /** A pattern of disputes or chargebacks beyond ordinary bad luck. */
    case RepeatedDispute = 'repeated_dispute';

    /** One device or one person behind many accounts. */
    case DeviceFarming = 'device_farming';

    /** Positions that are impossible, spoofed, or inconsistent with the journey. */
    case SuspiciousLocation = 'suspicious_location';

    /** A session used from somewhere or something that does not fit its history. */
    case SuspiciousSession = 'suspicious_session';

    /**
     * Whether a positive finding may block the action outright.
     *
     * Deliberately narrow. Blocking a genuine customer at checkout costs more
     * than reviewing a fraudulent one afterwards, so most signals inform review
     * rather than refusal — and the ones that can block are the ones where the
     * platform, not the customer, carries the loss.
     */
    public function mayBlockSynchronously(): bool
    {
        return $this === self::PaymentAbuse || $this === self::CouponAbuse;
    }
}
