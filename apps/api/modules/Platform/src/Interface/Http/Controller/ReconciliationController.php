<?php

declare(strict_types=1);

namespace EruoFood\Platform\Interface\Http\Controller;

use EruoFood\Shared\Application\Reconciliation\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a client calls when it comes back and needs to know what happened.
 *
 * ## The recovery sequence this serves
 *
 * authenticate → **synchronise authoritative server state** → reconcile pending
 * operations → update local cache → resume. This is the middle step, and it is
 * the one that stops an app from restoring its own optimistic state over the
 * server's. Local state describes what the app *tried*; only the server knows
 * what took effect.
 *
 * ## Ownership
 *
 * The account comes from the authenticated token, never from the request body.
 * An idempotency key is a client-chosen string, so answering on the key alone
 * would let anybody enumerate keys and read other people's payment outcomes.
 * A key belonging to somebody else is answered identically to one that never
 * existed — see {@see ReconciliationService} for why that symmetry is
 * deliberate rather than lazy.
 *
 * ## Read-only
 *
 * Nothing here re-runs, cancels or repairs anything. A recovery endpoint that
 * mutates can make an outage worse than the crash that caused it.
 */
final readonly class ReconciliationController
{
    /** More than a returning client should ever have queued, and a cheap DoS bound. */
    private const MAX_OPERATIONS = 50;

    public function __construct(private ReconciliationService $reconciliation)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPERATIONS],
            'operations.*.scope' => ['required', 'string', 'max:64'],
            'operations.*.key' => ['required', 'string', 'max:255'],
        ]);

        /** @var list<array{scope: string, key: string}> $operations */
        $operations = $data['operations'];

        $userId = (string) $request->attributes->get('auth_user_id', '');

        if ($userId === '') {
            // Belt and braces behind `auth.jwt`. Reconciliation without an
            // established account would answer on key alone, which is precisely
            // the enumeration this endpoint must not allow.
            return new JsonResponse(['error' => [
                'code' => 'NOT_AUTHORIZED',
                'message' => 'Reconciliation requires an authenticated account.',
            ]], 403);
        }

        $results = $this->reconciliation->reconcileMany($userId, $operations);

        return new JsonResponse([
            'data' => [
                'operations' => array_map(
                    static fn ($operation): array => $operation->toArray(),
                    $results,
                ),
                // The client sets its own clock against this rather than
                // trusting the device's, which may be wrong or deliberately
                // altered. Server time is the only authoritative time.
                'server_time' => now()->toAtomString(),
            ],
        ]);
    }
}
