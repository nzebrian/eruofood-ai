<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Controller;

use EruoFood\Ai\Application\Service\AiUsageService;
use EruoFood\Ai\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Ai\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The caller's AI usage & cost summary (for the "AI settings" screen). */
final readonly class AiUsageController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(private AiUsageService $usage)
    {
    }

    public function me(Request $request): JsonResponse
    {
        $summary = $this->usage->summaryForUser(
            $this->currentUserId($request),
            (int) $request->integer('days', 30),
        );

        return $this->data($summary->toArray());
    }
}
