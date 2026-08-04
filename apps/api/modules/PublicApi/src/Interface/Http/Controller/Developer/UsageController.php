<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Developer;

use EruoFood\PublicApi\Application\Service\ApplicationService;
use EruoFood\PublicApi\Application\Service\DeveloperService;
use EruoFood\PublicApi\Application\Service\QuotaService;
use EruoFood\PublicApi\Interface\Http\Concerns\ResolvesDeveloper;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API usage statistics for an application the developer owns (quota consumption + limits). */
final class UsageController
{
    use ResolvesDeveloper;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly QuotaService $quotas,
        private readonly ApplicationService $applications,
        private readonly DeveloperService $developers,
    ) {
    }

    public function show(Request $request, string $applicationId): JsonResponse
    {
        // Ownership check.
        $this->applications->get($applicationId, $this->developerId($request, $this->developers));

        return $this->item([
            'application_id' => $applicationId,
            'quota' => $this->quotas->usage($applicationId),
            'rate_limit' => [
                'per_minute' => (int) config('publicapi.rate_limit.per_minute', 120),
                'burst' => (int) config('publicapi.rate_limit.burst', 40),
            ],
        ]);
    }
}
