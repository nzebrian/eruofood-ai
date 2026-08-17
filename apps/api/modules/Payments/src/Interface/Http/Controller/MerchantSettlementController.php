<?php

declare(strict_types=1);

namespace EruoFood\Payments\Interface\Http\Controller;

use EruoFood\Payments\Application\Port\MerchantDirectory;
use EruoFood\Payments\Application\Service\PaymentsPresenter;
use EruoFood\Payments\Application\Service\SettlementRunService;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Settlement\PayableAccrual;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Payments\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Payments\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a merchant can see about their own money.
 *
 * ## Every query is scoped, and scoped the same way
 *
 * The merchant id comes from the URL, and {@see assertOwned()} checks it
 * against the merchants the authenticated user actually owns before anything is
 * read. Every action on this controller goes through it — there is no method
 * that trusts the path parameter.
 *
 * A merchant asking about somebody else's settlements gets a **404**, not a
 * 403. A 403 confirms the id exists, which is the whole of what an attacker
 * enumerating ids wants to learn.
 *
 * ## Read-only
 *
 * There is no merchant-facing endpoint that computes, approves, executes or
 * cancels anything. A merchant seeing what they are owed is a different
 * capability from a merchant being able to ask for it, and only the first is in
 * M27.
 */
final readonly class MerchantSettlementController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private SettlementRunService $settlements,
        private SettlementRunRepository $runs,
        private PayableAccrualRepository $accruals,
        private MerchantDirectory $merchants,
        private PaymentsPresenter $presenter,
    ) {
    }

    public function payable(Request $request, string $merchantId): JsonResponse
    {
        $type = $this->assertOwned($request, $merchantId);

        return $this->data($this->presenter->payable($this->settlements->payableFor($type, $merchantId)));
    }

    public function accruals(Request $request, string $merchantId): JsonResponse
    {
        $type = $this->assertOwned($request, $merchantId);

        $page = $this->accruals->forMerchant(
            $type,
            $merchantId,
            (int) $request->integer('page', 1),
            min(100, max(1, (int) $request->integer('per_page', 20))),
        );

        return $this->paginated($page, fn (PayableAccrual $a): array => $this->presenter->accrual($a));
    }

    public function settlements(Request $request, string $merchantId): JsonResponse
    {
        $type = $this->assertOwned($request, $merchantId);

        $page = $this->runs->forMerchant(
            $type,
            $merchantId,
            (int) $request->integer('page', 1),
            min(100, max(1, (int) $request->integer('per_page', 20))),
        );

        return $this->paginated($page, fn (SettlementRun $r): array => $this->presenter->settlementRun($r));
    }

    public function show(Request $request, string $merchantId, string $id): JsonResponse
    {
        $type = $this->assertOwned($request, $merchantId);

        $run = $this->runs->findById($id);

        // Two checks, and the second is the one that matters: a run id is a
        // uuid somebody could hold from a support conversation, and it must not
        // resolve just because the caller owns *a* merchant.
        if ($run === null || $run->merchantId() !== $merchantId || $run->merchantType() !== $type) {
            throw PaymentsNotFound::of('settlement run', $id);
        }

        return $this->data($this->presenter->settlementRun($run));
    }

    /**
     * Confirm the caller owns this merchant, and return its type.
     *
     * The type is derived from ownership rather than taken from the request:
     * accepting a `merchant_type` parameter would let a caller who owns rider
     * X ask about vendor X, and the ids are independent sequences.
     */
    private function assertOwned(Request $request, string $merchantId): string
    {
        $userId = $this->currentUserId($request);

        if (! in_array($merchantId, $this->merchants->merchantsFor($userId), true)) {
            // Deliberately "not found" rather than "not allowed".
            throw PaymentsNotFound::of('merchant', $merchantId);
        }

        foreach (['vendor', 'driver'] as $type) {
            if ($this->merchants->ownerOf($type, $merchantId) === $userId) {
                return $type;
            }
        }

        // Owned according to the listing but not resolvable by type — a
        // directory inconsistency rather than a caller error, and not something
        // to guess a type for.
        throw new PaymentsNotAuthorized('This merchant cannot be resolved for the current user.');
    }
}
