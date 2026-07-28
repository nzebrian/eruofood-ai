<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Seeder;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Config\FeatureFlag;
use EruoFood\Admin\Domain\Config\FeatureFlagRepository;
use EruoFood\Admin\Domain\Config\Setting;
use EruoFood\Admin\Domain\Config\SettingRepository;
use Illuminate\Database\Seeder;

/**
 * Seeds the default system settings and feature flags so the configuration
 * panels are populated out of the box. Idempotent — existing keys are left
 * untouched.
 */
final class AdminSeeder extends Seeder
{
    /** @var list<array{key: string, group: string, value: string, secret: bool, description: string}> */
    private const SETTINGS = [
        ['key' => 'app.name', 'group' => 'app', 'value' => 'EruoFood AI', 'secret' => false, 'description' => 'Public application name'],
        ['key' => 'app.support_email', 'group' => 'app', 'value' => 'support@eruofood.ai', 'secret' => false, 'description' => 'Support contact email'],
        ['key' => 'regional.default_locale', 'group' => 'regional', 'value' => 'en', 'secret' => false, 'description' => 'Default locale'],
        ['key' => 'regional.default_timezone', 'group' => 'regional', 'value' => 'Africa/Lagos', 'secret' => false, 'description' => 'Default timezone'],
        ['key' => 'regional.default_currency', 'group' => 'regional', 'value' => 'NGN', 'secret' => false, 'description' => 'Default currency'],
        ['key' => 'ai.default_provider', 'group' => 'ai', 'value' => 'anthropic', 'secret' => false, 'description' => 'Default AI provider'],
        ['key' => 'payment.default_provider', 'group' => 'payment', 'value' => 'paystack', 'secret' => false, 'description' => 'Default payment provider'],
        ['key' => 'notification.from_name', 'group' => 'notification', 'value' => 'EruoFood', 'secret' => false, 'description' => 'Notification sender name'],
    ];

    /** @var list<array{key: string, enabled: bool, description: string}> */
    private const FLAGS = [
        ['key' => 'ai_recipe_generation', 'enabled' => true, 'description' => 'AI recipe generation'],
        ['key' => 'marketplace_checkout', 'enabled' => true, 'description' => 'Marketplace checkout'],
        ['key' => 'wallet_payments', 'enabled' => true, 'description' => 'Pay from wallet'],
        ['key' => 'new_onboarding', 'enabled' => false, 'description' => 'New vendor onboarding flow'],
    ];

    public function run(): void
    {
        /** @var SettingRepository $settings */
        $settings = app(SettingRepository::class);
        /** @var FeatureFlagRepository $flags */
        $flags = app(FeatureFlagRepository::class);
        $now = new DateTimeImmutable();

        foreach (self::SETTINGS as $s) {
            if ($settings->findByKey($s['key']) === null) {
                $settings->save(Setting::define($s['key'], $s['group'], $s['value'], $s['secret'], $s['description'], $now));
            }
        }

        foreach (self::FLAGS as $f) {
            if ($flags->findByKey($f['key']) === null) {
                $flags->save(FeatureFlag::define($f['key'], $f['enabled'], $f['description'], $now));
            }
        }
    }
}
