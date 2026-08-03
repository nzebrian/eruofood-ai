<?php

declare(strict_types=1);

namespace EruoFood\Platform\Interface\Http\Controller;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Readiness probe for orchestration (Kubernetes readinessProbe / LB health).
 *
 * Unlike /health (liveness — the process is up), readiness asserts the service
 * can actually serve traffic: the database and Redis are reachable and the
 * schema is migrated. Returns 200 when ready, 503 otherwise, so the platform
 * withholds traffic from a pod that is up but not yet serviceable
 * (docs/PRODUCTION_DEPLOYMENT.md §5). Operational endpoint only — no business logic.
 */
final readonly class ReadinessController
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private RedisFactory $redis,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): mixed => $this->db->connection()->select('select 1')),
            'redis' => $this->check(fn (): mixed => $this->redis->connection()->ping()),
        ];

        $ready = ! in_array(false, $checks, true);

        return new JsonResponse(
            ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
