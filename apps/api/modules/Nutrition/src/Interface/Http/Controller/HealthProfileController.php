<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Input\HealthProfileInput;
use EruoFood\Nutrition\Application\Service\HealthProfileService;
use EruoFood\Nutrition\Application\Service\NutritionPresenter;
use EruoFood\Nutrition\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Nutrition\Interface\Http\Request\HealthProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The caller's health profile (read + create/update). */
final readonly class HealthProfileController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private HealthProfileService $profiles,
        private NutritionPresenter $presenter,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $profile = $this->profiles->get($this->currentUserId($request));
        if ($profile === null) {
            return new JsonResponse(['data' => null]);
        }

        return $this->data($this->presenter->profile($profile));
    }

    public function update(HealthProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->save(
            $this->currentUserId($request),
            HealthProfileInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->profile($profile));
    }
}
