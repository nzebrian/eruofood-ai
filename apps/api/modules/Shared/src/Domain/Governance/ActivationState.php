<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Governance;

/**
 * How far the repository has got towards being able to switch governance on.
 *
 * ## There is deliberately no `Active` case
 *
 * Not an oversight, and not a gap to be filled in later. Whether governance is
 * *active* is a fact about GitHub, and nothing in this repository can establish
 * it. A complete, valid, fully-resolved identity file proves that somebody has
 * written down who should review what. It does not prove those accounts exist,
 * that they have write access, that the ruleset was ever created, or that
 * GitHub is enforcing it.
 *
 * That inversion — treating a local file as evidence of a remote guarantee — is
 * the exact defect M29-A found: six team owners in `.github/CODEOWNERS`, none of
 * which could resolve, sitting there looking configured for months. Adding an
 * `Active` case here would rebuild it one layer up, and this time the thing
 * asserting a false state would be the validator itself.
 *
 * So the ladder stops at {@see self::ReadyForActivation}. The last rung is
 * climbed by an administrator against GitHub, and is reported as
 * EXTERNAL / ADMIN REQUIRED until observed there.
 */
enum ActivationState: string
{
    /**
     * No active identity configuration exists.
     *
     * The expected and correct state until a repository administrator supplies
     * real handles. It is not a failure — CODEOWNERS is inert, every rule is
     * commented out, and nothing claims an owner it cannot resolve.
     */
    case Unconfigured = 'unconfigured';

    /**
     * An active identity configuration exists but cannot be used.
     *
     * Roles missing, values empty, placeholders left in, example markers
     * carried over, or syntax GitHub will reject. The worst state to be in
     * quietly, which is why it is a hard failure rather than a warning.
     */
    case Incomplete = 'incomplete';

    /**
     * Every identity resolves locally. GitHub has still not been asked.
     *
     * The point at which an administrator may run `APPLY_GOVERNANCE.md`. Read
     * it as "nothing further is blocked on this repository", never as
     * "protected".
     */
    case ReadyForActivation = 'ready_for_activation';

    public function blocksActivation(): bool
    {
        return $this !== self::ReadyForActivation;
    }

    public function summary(): string
    {
        return match ($this) {
            self::Unconfigured => 'no active identity configuration — CODEOWNERS stays inert (expected pre-handover)',
            self::Incomplete => 'active identity configuration present but unusable — see findings',
            self::ReadyForActivation => 'identities resolve locally; GitHub-side application still required',
        };
    }
}
