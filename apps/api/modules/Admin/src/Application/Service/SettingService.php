<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Config\FeatureFlag;
use EruoFood\Admin\Domain\Config\FeatureFlagRepository;
use EruoFood\Admin\Domain\Config\Setting;
use EruoFood\Admin\Domain\Config\SettingRepository;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Event\MaintenanceModeToggled;
use EruoFood\Admin\Domain\Event\SettingChanged;
use EruoFood\Admin\Domain\Exception\AdminNotFound;
use EruoFood\Shared\Domain\EventBus;

/**
 * System Configuration: typed settings (app/AI/payment/notification/email/SMS/
 * regional), feature flags, and maintenance mode. Every change is audit-logged
 * and publishes {@see SettingChanged} (or {@see MaintenanceModeToggled}) so
 * caches and dependent contexts can react — never by a direct call.
 */
final readonly class SettingService
{
    private const MAINTENANCE_GROUP = 'maintenance';
    private const MAINTENANCE_ENABLED = 'maintenance.enabled';
    private const MAINTENANCE_MESSAGE = 'maintenance.message';

    public function __construct(
        private SettingRepository $settings,
        private FeatureFlagRepository $flags,
        private AuditService $audit,
        private EventBus $events,
    ) {
    }

    // ---- Settings --------------------------------------------------------

    /** @return list<Setting> */
    public function listSettings(?string $group = null): array
    {
        return $this->settings->all($group);
    }

    public function updateSetting(string $actorId, string $key, string $value): Setting
    {
        $setting = $this->settings->findByKey($key) ?? throw AdminNotFound::of('setting', $key);
        $setting->changeValue($value, new DateTimeImmutable());
        $this->settings->save($setting);

        $this->audit->record($actorId, AuditCategory::Config, 'config.setting_changed', 'setting', $key, [
            'group' => $setting->group(),
            'value' => $setting->isSecret() ? '[redacted]' : $value,
        ]);
        $this->events->publish(new SettingChanged($key, $setting->group(), $actorId));

        return $setting;
    }

    // ---- Feature flags ---------------------------------------------------

    /** @return list<FeatureFlag> */
    public function listFlags(): array
    {
        return $this->flags->all();
    }

    public function setFlag(string $actorId, string $key, bool $enabled): FeatureFlag
    {
        $flag = $this->flags->findByKey($key) ?? throw AdminNotFound::of('feature flag', $key);
        $now = new DateTimeImmutable();
        $enabled ? $flag->enable($now) : $flag->disable($now);
        $this->flags->save($flag);

        $this->audit->record($actorId, AuditCategory::Config, 'config.flag_changed', 'feature_flag', $key, [
            'enabled' => $enabled,
        ]);
        $this->events->publish(new SettingChanged($key, 'feature_flags', $actorId));

        return $flag;
    }

    // ---- Maintenance mode ------------------------------------------------

    public function setMaintenance(string $actorId, bool $enabled, ?string $message): void
    {
        $now = new DateTimeImmutable();

        $enabledSetting = $this->settings->findByKey(self::MAINTENANCE_ENABLED)
            ?? Setting::define(self::MAINTENANCE_ENABLED, self::MAINTENANCE_GROUP, '0', false, 'Maintenance mode switch', $now);
        $enabledSetting->changeValue($enabled ? '1' : '0', $now);
        $this->settings->save($enabledSetting);

        $messageSetting = $this->settings->findByKey(self::MAINTENANCE_MESSAGE)
            ?? Setting::define(self::MAINTENANCE_MESSAGE, self::MAINTENANCE_GROUP, '', false, 'Maintenance banner message', $now);
        $messageSetting->changeValue($message ?? '', $now);
        $this->settings->save($messageSetting);

        $this->audit->record($actorId, AuditCategory::Config, 'config.maintenance_toggled', 'maintenance', null, [
            'enabled' => $enabled,
        ]);
        $this->events->publish(new MaintenanceModeToggled($enabled, $message, $actorId));
    }

    /**
     * The current maintenance state, for the storefront gate.
     *
     * @return array{enabled: bool, message: string|null}
     */
    public function maintenance(): array
    {
        $enabled = $this->settings->findByKey(self::MAINTENANCE_ENABLED);
        $message = $this->settings->findByKey(self::MAINTENANCE_MESSAGE);

        return [
            'enabled' => $enabled !== null && $enabled->value() === '1',
            'message' => $message?->value() !== '' ? $message?->value() : null,
        ];
    }
}
