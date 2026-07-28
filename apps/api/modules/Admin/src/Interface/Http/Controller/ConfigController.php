<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Controller;

use EruoFood\Admin\Application\Service\AdminPresenter;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Application\Service\SettingService;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin;
use EruoFood\Admin\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** System Configuration: settings, feature flags and maintenance mode. */
final class ConfigController
{
    use AuthorizesAdmin;
    use RespondsWithData;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly SettingService $settings,
        private readonly AdminPresenter $presenter,
    ) {
    }

    protected function permissions(): PermissionService
    {
        return $this->permissions;
    }

    public function listSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::CONFIG_READ);
        $group = $request->query('group');

        return $this->data(['settings' => array_map(
            fn ($s): array => $this->presenter->setting($s),
            $this->settings->listSettings(is_string($group) ? $group : null),
        )]);
    }

    public function updateSetting(Request $request, string $key): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONFIG_WRITE);
        $data = $request->validate(['value' => ['required', 'string']]);

        return $this->data($this->presenter->setting($this->settings->updateSetting($actor, $key, $data['value'])));
    }

    public function listFlags(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::CONFIG_READ);

        return $this->data(['flags' => array_map(
            fn ($f): array => $this->presenter->flag($f),
            $this->settings->listFlags(),
        )]);
    }

    public function setFlag(Request $request, string $key): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONFIG_WRITE);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return $this->data($this->presenter->flag($this->settings->setFlag($actor, $key, (bool) $data['enabled'])));
    }

    public function maintenance(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::CONFIG_READ);

        return $this->data($this->settings->maintenance());
    }

    public function setMaintenance(Request $request): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONFIG_WRITE);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $this->settings->setMaintenance($actor, (bool) $data['enabled'], $data['message'] ?? null);

        return $this->data($this->settings->maintenance());
    }
}
