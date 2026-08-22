<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Governance;

/**
 * `.github/governance/ownership.json`, parsed and checked.
 *
 * Small on purpose. Its whole job is to turn one JSON file into a mode plus a
 * list of real humans, and to refuse the two ways that file could lie:
 *
 * - a mode nobody implemented, which would otherwise fall through to a default
 *   and silently pick a policy;
 * - a participant who is not a person.
 *
 * The second is the one worth spelling out. Claude and ChatGPT wrote a large
 * share of the governance in this repository. Neither can review a pull request,
 * hold repository access, or be accountable for a change that moves money — and
 * an assistant handle listed as the second reviewer would satisfy every check
 * here while defeating the entire purpose of the review it simulates. So the
 * names are rejected wherever an identity is expected, not merely discouraged in
 * prose.
 */
final readonly class OwnershipDeclaration
{
    /**
     * Handles that are tools rather than people.
     *
     * Matched case-insensitively against the handle with any leading `@`
     * removed, plus a suffix rule for GitHub's own `[bot]` convention. Kept as
     * an exact list rather than a fuzzy pattern: `bot` appears inside plenty of
     * legitimate usernames, and a governance check that rejects a real
     * contributor is its own kind of failure.
     */
    private const NON_HUMAN_HANDLES = [
        'chatgpt', 'claude', 'openai', 'anthropic', 'gpt', 'gpt-4', 'gpt4',
        'copilot', 'github-copilot', 'ai-assistant', 'assistant', 'bot',
    ];

    /**
     * @param list<string> $humanParticipants
     * @param list<IdentityFinding> $findings
     */
    private function __construct(
        public OwnershipMode $mode,
        public string $repository,
        public string $repositoryOwner,
        public array $humanParticipants,
        public array $findings,
    ) {
    }

    /**
     * @param array<mixed>|null $document decoded ownership.json, or null when absent
     */
    public static function fromArray(?array $document): self
    {
        $findings = [];

        if ($document === null) {
            // Absent is a hard error rather than a default. A repository whose
            // governance does not say how many people govern it cannot have its
            // ruleset checked against the truth, and guessing would pick a
            // policy on the reader's behalf.
            return new self(
                OwnershipMode::SoleOwner,
                '',
                '',
                [],
                [IdentityFinding::error(
                    'OWNERSHIP_DECLARATION_MISSING',
                    '.github/governance/ownership.json does not exist.',
                    'Create it. Governance cannot be validated against a repository whose ownership model is unstated — the approval count and code-owner requirement mean different things under one human and under several.',
                )],
            );
        }

        $rawMode = $document['mode'] ?? null;
        $mode = is_string($rawMode) ? OwnershipMode::tryFrom($rawMode) : null;

        if ($mode === null) {
            $findings[] = IdentityFinding::error(
                'OWNERSHIP_MODE_UNKNOWN',
                sprintf('"%s" is not a governance ownership mode.', is_string($rawMode) ? $rawMode : json_encode($rawMode)),
                'Use SOLE_OWNER or MULTI_PERSON. There is deliberately no default: an unrecognised mode must fail rather than fall through to a policy nobody chose.',
            );
            $mode = OwnershipMode::SoleOwner;
        }

        $repository = is_string($document['repository'] ?? null) ? $document['repository'] : '';
        $owner = is_string($document['repository_owner'] ?? null) ? $document['repository_owner'] : '';

        if ($owner === '') {
            $findings[] = IdentityFinding::error(
                'OWNERSHIP_OWNER_MISSING',
                'The declaration names no repository_owner.',
                'Set repository_owner to the account that owns the repository. Several rules compare an identity against it.',
            );
        }

        $participants = [];

        foreach (is_array($document['human_participants'] ?? null) ? $document['human_participants'] : [] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                $findings[] = IdentityFinding::error(
                    'OWNERSHIP_PARTICIPANT_INVALID',
                    'human_participants contains an entry that is not a handle.',
                    'Every entry must be a real GitHub username.',
                );

                continue;
            }

            $handle = trim($entry);

            if (self::isNonHuman($handle)) {
                $findings[] = IdentityFinding::error(
                    'OWNERSHIP_PARTICIPANT_NOT_HUMAN',
                    sprintf('"%s" is an AI assistant or bot, not a human participant.', $handle),
                    'Remove it. An assistant cannot hold repository access, approve a pull request, or be accountable for a change that moves money. Listing one as a participant would satisfy every check in this repository while providing none of the review it appears to provide.',
                );

                continue;
            }

            $participants[] = $handle;
        }

        if ($participants === []) {
            $findings[] = IdentityFinding::error(
                'OWNERSHIP_NO_HUMAN_PARTICIPANTS',
                'The declaration lists no human participants.',
                'Name at least the repository owner. A governance model with nobody in it is not a model.',
            );
        }

        // The mode must match the people, in both directions. One human cannot
        // satisfy MULTI_PERSON, and declaring SOLE_OWNER while several people
        // hold access understates the review that is actually available.
        if ($mode === OwnershipMode::MultiPerson && count($participants) < 2) {
            $findings[] = IdentityFinding::error(
                'OWNERSHIP_MODE_CONTRADICTS_PARTICIPANTS',
                sprintf('Mode is MULTI_PERSON but only %d human participant(s) are declared.', count($participants)),
                'Either add the second real human, or set mode to SOLE_OWNER. MULTI_PERSON requires an approving review, and one person cannot approve their own pull request.',
            );
        }

        if ($mode === OwnershipMode::SoleOwner && count($participants) > 1) {
            $findings[] = IdentityFinding::warning(
                'OWNERSHIP_MODE_UNDERSTATES_PARTICIPANTS',
                sprintf('Mode is SOLE_OWNER but %d human participants are declared.', count($participants)),
                'Independent review is available and is being deferred anyway. Consider moving to MULTI_PERSON.',
            );
        }

        return new self($mode, $repository, $owner, $participants, $findings);
    }

    /** Whether a handle names a tool rather than a person. */
    public static function isNonHuman(string $handle): bool
    {
        $normalised = strtolower(trim(ltrim(trim($handle), '@')));

        if (str_ends_with($normalised, '[bot]')) {
            return true;
        }

        return in_array($normalised, self::NON_HUMAN_HANDLES, true);
    }

    /** @return list<IdentityFinding> */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, static fn (IdentityFinding $f): bool => $f->isError()));
    }

    public function isUsable(): bool
    {
        return $this->errors() === [];
    }
}
