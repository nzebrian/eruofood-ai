<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Controller;

use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Exception\VerificationNotAuthorized;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;
use EruoFood\Verification\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Verification\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The subject-facing surface: start my verification, check where it stands.
 *
 * Every read is scoped to the caller. A case is addressed by its own id, which
 * is a guessable-in-principle UUID, so {@see assertOwnedBy()} re-checks
 * ownership on the loaded case rather than trusting the identifier — the same
 * object-level authorisation discipline the platform applies to orders and
 * wallets. A case belonging to someone else is refused with a message that does
 * not reveal whether it exists.
 */
final readonly class VerificationController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private VerificationService $verification,
        private VerificationPresenter $presenter,
    ) {
    }

    /** Where the caller's own identity verification stands. */
    public function me(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $case = $this->verification->latestFor(SubjectType::Customer, $userId, CaseType::Identity);

        if ($case === null) {
            return $this->data([
                'status' => 'not_started',
                'case' => null,
            ]);
        }

        return $this->data([
            'status' => $case->status()->value,
            'case' => $this->presenter->subjectView($case),
        ]);
    }

    /** Begin (or resume) the caller's own identity verification. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $userId = $this->currentUserId($request);

        $case = $this->verification->openCase(
            SubjectType::Customer,
            $userId,
            CaseType::Identity,
            (string) $data['country_code'],
        );

        $started = $this->verification->startVerification(
            $case->id(),
            ['document', 'liveness', 'face_match'],
            \EruoFood\Verification\Domain\Enum\ActorType::Subject,
            $userId,
        );

        return $this->data($this->presenter->subjectView($started), 201);
    }

    /** A single case belonging to the caller. */
    public function show(Request $request, string $id): JsonResponse
    {
        $case = $this->verification->getCase($id);
        $this->assertOwnedBy($case, $this->currentUserId($request));

        return $this->data($this->presenter->subjectView($case));
    }

    /**
     * Object-level authorisation.
     *
     * Business cases are keyed by business id rather than user id, so they are
     * never served through this subject-facing route at all — a merchant reads
     * their KYB through the business endpoints, which check ownership of the
     * business itself.
     */
    private function assertOwnedBy(VerificationCase $case, string $userId): void
    {
        if (! $case->belongsToSubject(SubjectType::Customer, $userId)
            && ! $case->belongsToSubject(SubjectType::Rider, $userId)) {
            throw new VerificationNotAuthorized();
        }
    }
}
