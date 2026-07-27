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
                    $templates->nextIdentity(), $key, $channel, 'en', $subject, $body,
                ));
            }
        }
    }
}
