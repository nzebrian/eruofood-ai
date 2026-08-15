<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Http\Middleware;

use Closure;
use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\Correlation\CorrelationId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every API request a correlation id, and puts it in the logs.
 *
 * ## What this replaces
 *
 * `docs/OBSERVABILITY.md` recorded correlation IDs as delivered, on the strength
 * of `PublicApi\...\ApiRequestContext`. That middleware runs on Public API
 * routes only, generates a fresh id while ignoring any inbound one, sets it on
 * the response, and never puts it anywhere a log line can see it. Meanwhile
 * M24's `AuditingSensitiveAccessLogger` reads `X-Request-Id` off the request and
 * its docblock says "the platform already stamps" it — so on every main-API
 * route, the correlation id on a regulated-data access audit record was null.
 *
 * This middleware is prepended to the API group, so it covers all of it.
 *
 * ## Ordering
 *
 * Prepended deliberately. A request rejected by throttling or authentication
 * still produced a log line, and a 429 with no correlation id is precisely the
 * event somebody will later want to trace.
 */
final class AssignsCorrelationId
{
    /** Honoured on the way in; also the name of the response header. */
    private const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlation = CorrelationId::fromInbound($request->header(self::HEADER));

        CorrelationContext::set($correlation);

        // Every log line written during this request carries the ids, without
        // any call site having to remember to add them.
        Log::withContext($correlation->toLogContext());

        // Kept on the request so code holding a Request (M24's sensitive-access
        // logger among them) can read a trustworthy value rather than the raw
        // caller-supplied header.
        $request->attributes->set('correlation', $correlation);

        $response = $next($request);

        // The caller's own id when they sent one, so they can find this request
        // in their logs; ours when they did not.
        $response->headers->set(self::HEADER, $correlation->forResponse());

        return $response;
    }

    /**
     * Release the correlation once the response has been sent.
     *
     * Matters under Octane and long-lived workers, where the process survives
     * the request and would otherwise carry this id into the next one.
     */
    public function terminate(Request $request, Response $response): void
    {
        CorrelationContext::clear();
        Log::withoutContext();
    }
}
