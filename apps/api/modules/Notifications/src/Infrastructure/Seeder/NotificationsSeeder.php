<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Seeder;

use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Template\NotificationTemplateRepository;
use Illuminate\Database\Seeder;

/**
 * Seeds the default in-app + email notification templates for every mapped
 * event, so notifications render friendly copy out of the box. Idempotent via
 * the template's (key, channel, locale) uniqueness.
 */
final class NotificationsSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> key => [subject, body] */
    private const TEMPLATES = [
        'welcome' => ['Welcome to EruoFood', 'Hi, welcome to EruoFood AI! Your account is ready.'],
        'password_changed' => ['Your password was changed', 'Your password was just changed. If this wasn\'t you, contact support.'],
        'email_verified' => ['Email verified', 'Thanks — your email address is now verified.'],
        'two_factor_enabled' => ['Two-factor enabled', 'Two-factor authentication is now on for your account.'],
        'order_placed' => ['Order received', 'We\'ve received your order {{ order_id }}. We\'ll keep you posted.'],
        'payment_succeeded' => ['Payment successful', 'Your payment of {{ amount_minor }} ({{ currency }}) was successful.'],
        'payment_failed' => ['Payment failed', 'Your payment could not be completed: {{ reason }}.'],
        'wallet_credited' => ['Wallet credited', 'Your wallet was credited with {{ amount_minor }}.'],
        'wallet_low_balance' => ['Low wallet balance', 'Your wallet balance is low: {{ balance_minor }}.'],
        'settlement_completed' => ['Payout sent', 'A settlement of {{ net_minor }} ({{ currency }}) has been paid out.'],
        'nutrition_profile_updated' => ['Nutrition profile updated', 'Your nutrition profile was updated.'],
        'new_message' => ['New message', 'You have a new message: {{ preview }}'],
        'broadcast' => ['{{ subject }}', '{{ body }}'],

        /*
         * ---- KYC / KYB (M24) ----
         *
         * Every one of these says what happened and where to go, and none of
         * them says what was on a document, which check failed, or what a
         * reviewer saw. That is not squeamishness: an email sits in an inbox
         * that may be shared, forwarded to an accountant, or reached by whoever
         * compromises the account next. The secure application is where the
         * detail belongs, so the copy sends people there.
         *
         * Note the absence of any placeholder for a name, number or reason code.
         * A template can only render what the event map allows through, and
         * these entries are allow-listed down to identifiers and status.
         */
        'verification_submitted' => [
            'We have your verification',
            'Thanks — we have received your identity verification and it is now being checked. '
            .'Most checks finish within a few minutes, and we will email you as soon as there is a result. '
            .'You do not need to do anything else for now.',
        ],
        'verification_processing' => [
            'Your verification is being checked',
            'Your identity verification is being reviewed. We will let you know as soon as it is complete.',
        ],
        'verification_approved' => [
            'Your identity is verified',
            'Good news — your identity verification is complete and your account is fully verified. '
            .'Sign in to see everything that is now available to you.',
        ],
        'verification_rejected' => [
            'We could not verify your identity',
            'We were not able to complete your identity verification. '
            .'Sign in to see what is needed and, where possible, try again. '
            .'If you think this is a mistake, our support team can help.',
        ],
        'reverification_required' => [
            'Please verify your identity again',
            'We need you to complete identity verification again to keep your account fully active. '
            .'Sign in to start — it usually takes just a few minutes.',
        ],
        'rider_verification_approved' => [
            'You are approved to start delivering',
            'Your rider verification is complete. You can now go online and start accepting deliveries. '
            .'Sign in to the rider app to get started.',
        ],
        'kyb_submitted' => [
            'We have your business verification',
            'Thanks — we have received your business verification documents and they are now being checked. '
            .'We will email you as soon as there is a result. You can keep trading as normal in the meantime '
            .'unless we tell you otherwise.',
        ],
        'kyb_approved' => [
            'Your business is verified',
            'Your business verification is complete and your store is fully active on EruoFood. '
            .'Sign in to your merchant dashboard to see your status.',
        ],
        'kyb_rejected' => [
            'We could not verify your business',
            'We were not able to complete your business verification. '
            .'Sign in to your merchant dashboard to see what is needed and what to do next. '
            .'Our support team can help if anything is unclear.',
        ],
    ];

    public function run(): void
    {
        /** @var NotificationTemplateRepository $templates */
        $templates = app(NotificationTemplateRepository::class);

        foreach (self::TEMPLATES as $key => [$subject, $body]) {
            foreach ([NotificationChannel::InApp, NotificationChannel::Email] as $channel) {
                if ($templates->find($key, $channel, 'en') !== null) {
                    continue;
                }
                $templates->save(\EruoFood\Notifications\Domain\Template\NotificationTemplate::create(
                    $templates->nextIdentity(),
                    $key,
                    $channel,
                    'en',
                    $subject,
                    $body,
                ));
            }
        }
    }
}
