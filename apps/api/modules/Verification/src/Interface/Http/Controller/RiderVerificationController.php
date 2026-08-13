<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Controller;

use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Verification\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider KYC — mandatory, and the strictest identity flow the platform runs.
 *
 * A rider carries goods and money and meets customers at their homes, so the
 * checks are the full set: a government identity document, a driving licence
 * where the vehicle requires one, liveness, and a face match against the
 * document. Which of those a provider actually performs is decided by the
 * workflow configured for it, so the requirement list here is a statement of
 * intent that the adapter translates.
 *
 * The rider is always the subject of their own case — the case is keyed to the
 * authenticated user, never to an id supplied in the request, so one rider
 * cannot open or read a case against another.
 */
final readonly class RiderVerificationController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private VerificationService $verification,
        private VerificationPresenter $presenter,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            // Only riders on a vehicle that legally needs one are put through a
            // licence check; demanding it of a bicycle courier would be a
            // barrier with no purpose.
            'requires_driving_licence' => ['sometimes', 'boolean'],
        ]);

        $userId = $this->currentUserId($request);

        $checks = ['document', 'liveness', 'face_match'];
        if ((bool) ($data['requires_driving_licence'] ?? true)) {
            $checks[] = 'driving_licence';
        }

        $case = $this->verification->openCase(
            SubjectType::Rider,
            $userId,
            CaseType::Identity,
            (string) $data['country_code'],
        );

        $started = $this->verification->startVerification($case->id(), $checks, ActorType::Subject, $userId);

        return $this->data($this->presenter->subjectView($started), 201);
    }

    /** Where the calling rider's own verification stands. */
    public function status(Request $request): JsonResponse
    {
        $case = $this->verification->latestFor(
            SubjectType::Rider,
            $this->currentUserId($request),
            CaseType::Identity,
        );

        return $this->data([
            'status' => $case?->status()->value ?? 'not_started',
            // Spelled out so a rider app can show "you cannot accept deliveries
            // yet" without having to interpret status values itself.
            'eligible_for_delivery' => $case?->status()->isVerified() ?? false,
            'case' => $case !== null ? $this->presenter->subjectView($case) : null,
        ]);
    }
}
