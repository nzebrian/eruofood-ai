<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Controller;

use EruoFood\Verification\Application\Service\ReviewService;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\VerificationCase\StatusChange;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;
use EruoFood\Verification\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Verification\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The back-office review surface — the backend foundation the Global Command
 * Centre will sit on in M32.
 *
 * The route-level permissions do the coarse gating (`verification.read` to see
 * the queue, `verification.review` to decide, `verification.pii` to open
 * documents), which is why this controller reads thin. The one place it makes a
 * judgement is {@see documents()}, where the permission is passed explicitly
 * into the service so the authorisation decision is visible at the call site
 * rather than buried.
 *
 * Note that the queue, case detail and history routes never touch identity data
 * at all. Clearing a verification backlog — the ordinary daily job — is possible
 * for someone who cannot open a single document.
 */
final readonly class ReviewController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ReviewService $reviews,
        private VerificationPresenter $presenter,
    ) {
    }

    /** Cases waiting on a human, oldest first. */
    public function queue(Request $request): JsonResponse
    {
        $statuses = array_values(array_filter(array_map(
            static fn (string $value): ?VerificationStatus => VerificationStatus::tryFrom($value),
            (array) $request->query('status', []),
        )));

        $page = $this->reviews->queue(
            $statuses,
            SubjectType::tryFrom((string) $request->query('subject_type', '')),
            (int) $request->integer('page', 1),
            min(100, max(1, (int) $request->integer('per_page', 20))),
        );

        return $this->paginated($page, fn (VerificationCase $case): array => $this->presenter->reviewerView($case));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->data($this->presenter->reviewerView($this->reviews->getCase($id)));
    }

    /** The full transition history — who decided what, when, and why. */
    public function history(Request $request, string $id): JsonResponse
    {
        return $this->data(array_map(
            fn (StatusChange $change): array => $this->presenter->statusChange($change),
            $this->reviews->history($id),
        ));
    }

    /**
     * The regulated fields, behind `verification.pii`.
     *
     * An optional `reason` is captured and stored on the audit event: "why did
     * you need to look at this?" is the question an access review actually asks,
     * and capturing it at the moment of access is the only time it can be
     * answered honestly.
     */
    public function documents(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        return $this->data($this->reviews->sensitiveDocuments(
            caseId: $id,
            actorId: $this->currentUserId($request),
            // The route already enforces this; passing it keeps the service
            // honest for any future caller that forgets the middleware.
            hasPiiPermission: true,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        ));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['sometimes', 'string', 'max:500']]);

        $case = $this->reviews->approve(
            $id,
            $this->currentUserId($request),
            isset($data['note']) ? (string) $data['note'] : null,
        );

        return $this->data($this->presenter->reviewerView($case));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string'],
            'note' => ['sometimes', 'string', 'max:500'],
        ]);

        $reason = RejectionReason::tryFrom((string) $data['reason_code']) ?? RejectionReason::ManualRejection;

        $case = $this->reviews->reject(
            $id,
            $this->currentUserId($request),
            $reason,
            isset($data['note']) ? (string) $data['note'] : null,
        );

        return $this->data($this->presenter->reviewerView($case));
    }

    public function requireReverification(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['sometimes', 'string', 'max:500']]);

        $case = $this->reviews->requireReverification(
            $id,
            $this->currentUserId($request),
            isset($data['note']) ? (string) $data['note'] : null,
        );

        return $this->data($this->presenter->reviewerView($case));
    }

    /** The reason-code catalogue, so a reviewer picks from the same vocabulary the providers map onto. */
    public function reasonCodes(): JsonResponse
    {
        return $this->data(array_map(static fn (RejectionReason $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
            'retryable' => $reason->isRetryable(),
        ], RejectionReason::cases()));
    }
}
