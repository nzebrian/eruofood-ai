<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Governance;

/**
 * The rules deciding whether governance identities may be activated.
 *
 * ## What this is guarding against
 *
 * M29-A found `.github/CODEOWNERS` naming six `@eruofood/*` teams, every one of
 * them unresolvable, sitting in the repository for months looking configured.
 * Nothing in the repository could have caught it, because nothing in the
 * repository ever asked whether the names were real.
 *
 * This policy cannot ask that either — only GitHub can. What it can do is
 * refuse every cheaper way of arriving at the same outcome: a role left out, a
 * value left blank, a placeholder token carried into production, the example
 * file used as the live one, a handle in a syntax GitHub will not accept, a
 * release actor quietly acquiring the right to delete the tag it created.
 *
 * Each of those has the same signature as the original defect — the file reads
 * as configured and enforces nothing — and each is locally provable, so each is
 * a hard error here.
 *
 * ## What it deliberately cannot do
 *
 * It cannot report success. {@see ActivationState} has no `Active` case and
 * {@see IdentityAssessment::externalRequirements()} is never empty. The best
 * available outcome is "nothing further is blocked on this repository", which
 * is a statement about this repository and not about GitHub.
 */
final class IdentityPolicy
{
    /**
     * Values meaning "nobody has filled this in", compared lower-cased.
     *
     * Prefix-matched rather than compared exactly, because the shapes vary
     * (`<OWNER:FINANCE>`, `<EXAMPLE:handle>`, `change_me_before_activation`) and
     * a handle that begins with any of these is never a real account.
     */
    private const PLACEHOLDER_PREFIXES = [
        '<', '@<', 'change_me', 'changeme', 'example', '@example', 'placeholder',
        '@placeholder', 'todo', 'tbd', 'unresolved', 'your-', '@your-', 'xxx',
    ];

    /** Actor types GitHub accepts in `bypass_actors`. */
    private const ACTOR_TYPES = ['Integration', 'OrganizationAdmin', 'RepositoryRole', 'Team'];

    public function __construct(
        /**
         * The account that owns the repository, without the `@`.
         *
         * Supplied rather than hard-coded: the caller derives it from
         * `production-tags-ruleset.json`'s `_meta.applies_to`, so there is one
         * place the repository identity is written down.
         */
        private readonly string $repositoryOwner,
        /**
         * How many humans govern this repository.
         *
         * Changes one rule and one rule only: whether FINANCE naming the
         * repository owner is an error or a recorded deferral. It relaxes
         * nothing else, and it cannot — every other check here is about whether
         * a handle is real, which does not depend on how many people there are.
         *
         * Defaults to MULTI_PERSON so that a caller which forgets to pass a mode
         * gets the strict policy rather than the permissive one.
         */
        private readonly OwnershipMode $mode = OwnershipMode::MultiPerson,
    ) {
    }

    /**
     * @param array<mixed>|null $identities decoded active identity file, or null when none exists
     * @param string $codeownersBody raw `.github/CODEOWNERS`
     * @param list<array<mixed>> $tagRulesets `rulesets` from `production-tags-ruleset.json`
     */
    public function evaluate(?array $identities, string $codeownersBody, array $tagRulesets): IdentityAssessment
    {
        $findings = [];

        // Tag safety is evaluated whether or not identities exist. The two-ruleset
        // split protects release tags from the release actor, and it can be
        // collapsed by an edit to the artifact alone.
        foreach ($this->assessTagRulesets($tagRulesets, $identities) as $finding) {
            $findings[] = $finding;
        }

        // A placeholder in a *commented* CODEOWNERS rule is the expected
        // pre-handover state. A placeholder in an *active* rule is the defect.
        foreach ($this->assessCodeowners($codeownersBody) as $finding) {
            $findings[] = $finding;
        }

        if ($identities === null) {
            return new IdentityAssessment(
                $this->stateFor(ActivationState::Unconfigured, $findings),
                $findings,
                [],
                array_map(static fn (GovernanceRole $r): string => $r->value, GovernanceRole::cases()),
            );
        }

        [$identityFindings, $resolved, $unresolved] = $this->assessIdentities($identities);

        foreach ($identityFindings as $finding) {
            $findings[] = $finding;
        }

        $state = $unresolved === [] ? ActivationState::ReadyForActivation : ActivationState::Incomplete;

        return new IdentityAssessment(
            $this->stateFor($state, $findings),
            $findings,
            $resolved,
            $unresolved,
        );
    }

    /**
     * Any error downgrades the state. A configuration with an error is never
     * ready, and an *absent* configuration with a tag-safety error is not merely
     * unconfigured — something has been broken that was previously correct.
     *
     * @param list<IdentityFinding> $findings
     */
    private function stateFor(ActivationState $proposed, array $findings): ActivationState
    {
        $hasError = array_filter($findings, static fn (IdentityFinding $f): bool => $f->isError()) !== [];

        if (! $hasError) {
            return $proposed;
        }

        return $proposed === ActivationState::Unconfigured
            ? ActivationState::Unconfigured
            : ActivationState::Incomplete;
    }

    // -- Identities -----------------------------------------------------------

    /**
     * @param array<mixed> $identities
     * @return array{list<IdentityFinding>, array<string, list<string>>, list<string>}
     */
    private function assessIdentities(array $identities): array
    {
        $findings = [];
        $resolved = [];
        $unresolved = [];

        // 1. The example file, used as the live one.
        //
        // Checked before anything else because every other finding below would
        // be a true statement about a file that should not have been read at
        // all, and a screenful of them buries the one that matters.
        if (($identities['_example'] ?? false) === true) {
            $findings[] = IdentityFinding::error(
                'IDENTITY_EXAMPLE_USED_AS_ACTIVE',
                'The active identity configuration is the shipped example: it carries "_example": true.',
                'Copy identities.example.json to identities.json, replace every <EXAMPLE:...> value with a real handle, and delete the "_example" key. The example is a shape, not a starting set of people.',
            );

            // Every role in it is by definition unresolved.
            return [$findings, [], array_map(static fn (GovernanceRole $r): string => $r->value, GovernanceRole::cases())];
        }

        $codeowners = $identities['codeowners'] ?? null;

        if (! is_array($codeowners)) {
            $findings[] = IdentityFinding::error(
                'IDENTITY_CODEOWNERS_SECTION_MISSING',
                'The identity configuration has no "codeowners" object.',
                'Add a "codeowners" object keyed by role, as in identities.example.json.',
            );
            $codeowners = [];
        }

        // 2. Unknown roles. Rejected, not ignored: a typo leaves the real role
        //    unfilled while the file looks complete.
        $known = array_map(static fn (GovernanceRole $r): string => $r->value, GovernanceRole::codeownerRoles());

        foreach (array_keys($codeowners) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $findings[] = IdentityFinding::error(
                    'IDENTITY_ROLE_UNKNOWN',
                    sprintf('"%s" is not a governance role.', (string) $key),
                    sprintf(
                        'Remove it, or fix the spelling. Known roles: %s. There is no extensibility mechanism by design — adding a role means adding a GovernanceRole case and a <OWNER:...> token to CODEOWNERS in the same change, so the two cannot drift apart.',
                        implode(', ', $known),
                    ),
                );
            }
        }

        // 3. Every code-owner role present, non-empty, syntactically valid.
        foreach (GovernanceRole::codeownerRoles() as $role) {
            $handles = $this->handlesFor($codeowners[$role->value] ?? null);

            if ($handles === null) {
                $findings[] = IdentityFinding::error(
                    'IDENTITY_ROLE_MISSING',
                    sprintf('Role %s has no entry.', $role->value),
                    sprintf('Add "%s": { "handles": ["@someone"] }. Every <OWNER:%s> token in CODEOWNERS depends on it.', $role->value, $role->value),
                    $role,
                );
                $unresolved[] = $role->value;

                continue;
            }

            if ($handles === []) {
                $findings[] = IdentityFinding::error(
                    'IDENTITY_EMPTY',
                    sprintf('Role %s is present but names nobody.', $role->value),
                    'Supply at least one handle, or remove the role and its CODEOWNERS token together. An empty owner list routes review to nobody while reading as configured.',
                    $role,
                );
                $unresolved[] = $role->value;

                continue;
            }

            $roleOk = true;

            foreach ($handles as $handle) {
                foreach ($this->assessHandle($role, $handle) as $finding) {
                    $findings[] = $finding;
                    $roleOk = $roleOk && ! $finding->isError();
                }
            }

            $roleOk ? $resolved[$role->value] = $handles : $unresolved[] = $role->value;
        }

        // 4. The release actor.
        [$actorFindings, $actorResolved] = $this->assessReleaseActors($identities['release_actors'] ?? null);

        foreach ($actorFindings as $finding) {
            $findings[] = $finding;
        }

        $actorResolved
            ? $resolved[GovernanceRole::ReleaseActor->value] = $actorResolved
            : $unresolved[] = GovernanceRole::ReleaseActor->value;

        return [$findings, $resolved, array_values(array_unique($unresolved))];
    }

    /**
     * @return list<string>|null null when the role is absent entirely
     */
    private function handlesFor(mixed $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        $handles = is_array($entry) ? ($entry['handles'] ?? null) : null;

        if (! is_array($handles)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $h): string => is_scalar($h) ? trim((string) $h) : '',
            $handles,
        ));
    }

    /** @return list<IdentityFinding> */
    private function assessHandle(GovernanceRole $role, string $handle): array
    {
        if ($handle === '') {
            return [IdentityFinding::error(
                'IDENTITY_EMPTY',
                sprintf('Role %s contains an empty handle.', $role->value),
                'Remove the empty entry or fill it in. An empty string is not an owner.',
                $role,
            )];
        }

        if ($this->isPlaceholder($handle)) {
            return [IdentityFinding::error(
                'IDENTITY_PLACEHOLDER_ACTIVE',
                sprintf('Role %s still holds the placeholder "%s".', $role->value, $handle),
                'Replace it with a real GitHub handle. A placeholder that reaches CODEOWNERS resolves to nobody, and GitHub reports that as an unknown owner rather than as an error you will notice.',
                $role,
            )];
        }

        // An assistant cannot review a pull request or be accountable for a
        // change that moves money, but its handle would satisfy every other
        // check here — syntax, resolvability, not-the-owner. Rejected
        // explicitly, because the one thing a fabricated reviewer reliably does
        // is make the reports look finished.
        if (OwnershipDeclaration::isNonHuman($handle)) {
            return [IdentityFinding::error(
                'IDENTITY_NOT_HUMAN',
                sprintf('Role %s names "%s", which is an AI assistant or bot rather than a person.', $role->value, $handle),
                'Name a real human. Claude and ChatGPT contributed code to this repository; neither may be represented as a code owner, reviewer, release actor or approver. A synthetic second reviewer passes every check and provides none of the review it simulates.',
                $role,
            )];
        }

        $findings = [];

        // GitHub usernames: alphanumeric and single hyphens, no leading or
        // trailing hyphen, at most 39 characters. Compared strictly here rather
        // than loosely, because the looser check downstream in
        // verify_repository_governance.php is a backstop, not the gate.
        $isUser = preg_match('/^@[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $handle) === 1;
        $isTeam = preg_match('/^@[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}\/[A-Za-z0-9._-]{1,100}$/', $handle) === 1;
        $isEmail = filter_var($handle, FILTER_VALIDATE_EMAIL) !== false;

        if (! $isUser && ! $isTeam && ! $isEmail) {
            return [IdentityFinding::error(
                'IDENTITY_SYNTAX_INVALID',
                sprintf('Role %s names "%s", which is not a handle GitHub will accept.', $role->value, $handle),
                'Use @username, @org/team, or an email address tied to a GitHub account. Usernames are alphanumeric with single internal hyphens, at most 39 characters.',
                $role,
            )];
        }

        if ($isTeam) {
            $findings[] = IdentityFinding::warning(
                'IDENTITY_TEAM_REQUIRES_ORGANIZATION',
                sprintf('Role %s names the team "%s".', $role->value, $handle),
                'Teams exist only inside GitHub organizations. This repository is owned by a user account, so this handle cannot resolve until the repository is transferred. Use individual usernames, or complete the organization move first — this is the exact failure M29-A found.',
                $role,
            );
        }

        // An owner who authors every pull request cannot review one. GitHub does
        // not warn about this; it just stops requesting the review.
        if ($isUser && strcasecmp(ltrim($handle, '@'), $this->repositoryOwner) === 0) {
            if (! $role->guardsFinancialPaths()) {
                $findings[] = IdentityFinding::warning(
                    'IDENTITY_IS_REPOSITORY_OWNER',
                    sprintf('Role %s is the repository owner, "%s".', $role->value, $handle),
                    'Acceptable on a single-maintainer repository, but code-owner review is inert for these paths whenever that account is the author. Record the decision, or name a second reviewer.',
                    $role,
                );
            } elseif ($this->mode->requiresIndependentFinanceOwner()) {
                $findings[] = IdentityFinding::error(
                    'FINANCE_OWNER_IS_REPOSITORY_OWNER',
                    sprintf('Role %s is the repository owner, "%s".', $role->value, $handle),
                    'Name somebody else. This role owns the money-moving paths, and GitHub forbids approving your own pull request — so the owner of the repository reviewing their own financial changes is not four-eyes, it is none. M27 split finance permissions by consequence precisely so that one person could not do this.',
                    $role,
                );
            } else {
                // SOLE_OWNER. The requirement is not dropped and not quietly
                // met — it is recorded as absent, every run, so no report can be
                // read as evidence of a second pair of eyes that does not exist.
                $findings[] = IdentityFinding::warning(
                    'FINANCE_FOUR_EYES_DEFERRED',
                    sprintf('Role %s is the repository owner, "%s", and four-eyes review on the money-moving paths is NOT ACTIVE.', $role->value, $handle),
                    'Deferred under SOLE_OWNER mode because the repository has one human participant, not because the risk went away. Compensating controls: every settlement flag ships false, financial scheduled work is registered disabled, and the financial concurrency gate runs on every pull request. Resolve by granting a second real human write access and moving ownership.json to MULTI_PERSON. Do not resolve it by naming an assistant or a second account of the same person.',
                    $role,
                );
            }
        }

        return $findings;
    }

    /**
     * @return array{list<IdentityFinding>, list<string>}
     */
    private function assessReleaseActors(mixed $actors): array
    {
        if ($actors === null) {
            return [[IdentityFinding::error(
                'RELEASE_ACTOR_MISSING',
                'No release actor is configured.',
                'Add "release_actors": [ { "actor_id": <int>, "actor_type": "Integration", "bypass_mode": "always" } ]. Without one, the tag-creation ruleset denies release tags to everybody — the safe direction, but it stops releases, so it must be a decision rather than an omission.',
                GovernanceRole::ReleaseActor,
            )], []];
        }

        if (! is_array($actors) || $actors === []) {
            return [[IdentityFinding::error(
                'RELEASE_ACTOR_MISSING',
                'The release actor list is present but empty.',
                'Name at least one actor, or delete the key and accept that no release tag can be created until one exists.',
                GovernanceRole::ReleaseActor,
            )], []];
        }

        $findings = [];
        $resolved = [];

        foreach (array_values($actors) as $i => $actor) {
            if (! is_array($actor)) {
                $findings[] = IdentityFinding::error(
                    'RELEASE_ACTOR_SYNTAX_INVALID',
                    sprintf('Release actor #%d is not an object.', $i),
                    'Each entry is { "actor_id": <int>, "actor_type": ..., "bypass_mode": ... }.',
                    GovernanceRole::ReleaseActor,
                );

                continue;
            }

            $id = $actor['actor_id'] ?? null;
            $type = $actor['actor_type'] ?? null;
            $mode = $actor['bypass_mode'] ?? null;

            if (is_string($id) && $this->isPlaceholder($id)) {
                $findings[] = IdentityFinding::error(
                    'RELEASE_ACTOR_PLACEHOLDER_ACTIVE',
                    sprintf('Release actor #%d still holds the placeholder "%s".', $i, $id),
                    'Replace it with the numeric actor id from GitHub. Ruleset bypass actors are numeric ids, not handles — a string here is rejected by the API, and a placeholder string is rejected by this validator first.',
                    GovernanceRole::ReleaseActor,
                );

                continue;
            }

            if (! is_int($id) || $id <= 0) {
                $findings[] = IdentityFinding::error(
                    'RELEASE_ACTOR_SYNTAX_INVALID',
                    sprintf('Release actor #%d has actor_id %s.', $i, json_encode($id)),
                    'actor_id must be a positive integer. GitHub identifies bypass actors numerically; "@someone" is a code-owner handle and belongs in the codeowners section instead.',
                    GovernanceRole::ReleaseActor,
                );

                continue;
            }

            if (! is_string($type) || ! in_array($type, self::ACTOR_TYPES, true)) {
                $findings[] = IdentityFinding::error(
                    'RELEASE_ACTOR_SYNTAX_INVALID',
                    sprintf('Release actor #%d has actor_type %s.', $i, json_encode($type)),
                    'Use one of: '.implode(', ', self::ACTOR_TYPES).'.',
                    GovernanceRole::ReleaseActor,
                );

                continue;
            }

            if ($type === 'OrganizationAdmin') {
                $findings[] = IdentityFinding::error(
                    'RELEASE_ACTOR_NOT_A_NAMED_GRANT',
                    sprintf('Release actor #%d grants release authority to OrganizationAdmin.', $i),
                    'Name the actor. production-tags-ruleset.json\'s actor_placeholder_contract forbids this shortcut: "whoever happens to be an admin" is a category, and the set of people in it changes without anyone deciding that release authority should change with it.',
                    GovernanceRole::ReleaseActor,
                );

                continue;
            }

            if (! is_string($mode) || ! in_array($mode, ['always', 'pull_request'], true)) {
                $findings[] = IdentityFinding::error(
                    'RELEASE_ACTOR_SYNTAX_INVALID',
                    sprintf('Release actor #%d has bypass_mode %s.', $i, json_encode($mode)),
                    'Use "always" or "pull_request". Tag creation does not go through a pull request, so "always" is normally correct here.',
                    GovernanceRole::ReleaseActor,
                );

                continue;
            }

            if ($type === 'RepositoryRole') {
                $findings[] = IdentityFinding::warning(
                    'RELEASE_ACTOR_IS_A_ROLE_NOT_A_PERSON',
                    sprintf('Release actor #%d grants release authority to a repository role, not a named actor.', $i),
                    'Everybody holding that role can cut a production release, now and whenever somebody new is granted it. Prefer an Integration (a release GitHub App). If a role is intended, record the decision in APPLY_GOVERNANCE.md.',
                    GovernanceRole::ReleaseActor,
                );
            }

            $resolved[] = sprintf('%s#%d (%s)', $type, $id, $mode);
        }

        return [$findings, $resolved];
    }

    // -- CODEOWNERS -----------------------------------------------------------

    /**
     * @return list<IdentityFinding>
     */
    private function assessCodeowners(string $body): array
    {
        $findings = [];

        foreach (explode("\n", $body) as $i => $line) {
            $trimmed = trim($line);

            // A commented placeholder is the whole point of the M29-A hand-over
            // state: the domain structure is recorded, and nothing is claimed.
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $trimmed) ?: [];
            $pattern = (string) array_shift($parts);

            foreach ($parts as $owner) {
                if (preg_match('/^<[A-Z_:][^>]*>$/', $owner) === 1 || $this->isPlaceholder($owner)) {
                    $findings[] = IdentityFinding::error(
                        'CODEOWNERS_PLACEHOLDER_ACTIVE',
                        sprintf('CODEOWNERS line %d makes "%s" owned by the placeholder "%s".', $i + 1, $pattern, $owner),
                        'Either comment the rule out again, or substitute a real handle from the identity configuration. An active rule naming an unresolvable owner is the M29-A defect exactly: GitHub reports an unknown owner and either blocks every pull request or enforces nothing, and which one is not obvious until somebody tries to merge.',
                    );
                }
            }
        }

        return $findings;
    }

    // -- Tag rulesets ---------------------------------------------------------

    /**
     * The invariant that keeps a release tag a record rather than a draft.
     *
     * @param list<array<mixed>> $rulesets
     * @param array<mixed>|null $identities
     * @return list<IdentityFinding>
     */
    private function assessTagRulesets(array $rulesets, ?array $identities): array
    {
        $findings = [];

        $immutabilityTypes = ['deletion', 'non_fast_forward', 'update'];

        foreach ($rulesets as $ruleset) {
            $name = is_string($ruleset['name'] ?? null) ? $ruleset['name'] : '(unnamed)';
            $types = $this->ruleTypes($ruleset);
            $bypass = $ruleset['bypass_actors'] ?? null;
            $bypass = is_array($bypass) ? $bypass : [];

            $restrictsCreation = in_array('creation', $types, true);
            $enforcesImmutability = array_intersect($immutabilityTypes, $types) !== [];

            // The collapse. One ruleset that both restricts creation and enforces
            // immutability has to carry the release actors as bypass actors to be
            // usable at all — and bypass_actors is scoped to the whole ruleset,
            // so those actors are then exempt from deletion and update too. The
            // configuration reads as "tags are protected" and means "the release
            // actor may delete any release tag".
            if ($restrictsCreation && $enforcesImmutability) {
                $findings[] = IdentityFinding::error(
                    'TAG_RULESET_COLLAPSED',
                    sprintf('Ruleset "%s" restricts creation and enforces immutability at once.', $name),
                    'Split it back into two rulesets, as M29-A prepared. GitHub scopes bypass_actors to an entire ruleset, so any actor allowed to create a release tag in this ruleset is also allowed to delete and move one.',
                    GovernanceRole::ReleaseActor,
                );
            }

            if ($enforcesImmutability && $bypass !== []) {
                $findings[] = IdentityFinding::error(
                    'TAG_IMMUTABILITY_BYPASS_PRESENT',
                    sprintf('The immutability ruleset "%s" carries %d bypass actor(s).', $name, count($bypass)),
                    'Empty bypass_actors on this ruleset and keep it empty. A production tag its creator can delete or move is not a record of what shipped — and release.yml builds and promotes a container image from exactly that tag.',
                    GovernanceRole::ReleaseActor,
                );
            }
        }

        // A release actor reaching the immutability ruleset by name. Distinct
        // from the check above: that one fires on any bypass actor, this one
        // names the specific mistake — granting creation and immutability
        // bypass to the same actor across two rulesets, which looks correct in
        // each file viewed alone.
        $releaseActorIds = [];

        foreach (is_array($identities['release_actors'] ?? null) ? $identities['release_actors'] : [] as $actor) {
            if (is_array($actor) && is_int($actor['actor_id'] ?? null)) {
                $releaseActorIds[] = $actor['actor_id'];
            }
        }

        foreach ($rulesets as $ruleset) {
            if (array_intersect($immutabilityTypes, $this->ruleTypes($ruleset)) === []) {
                continue;
            }

            $bypass = is_array($ruleset['bypass_actors'] ?? null) ? $ruleset['bypass_actors'] : [];

            foreach ($bypass as $actor) {
                if (is_array($actor) && in_array($actor['actor_id'] ?? null, $releaseActorIds, true)) {
                    $findings[] = IdentityFinding::error(
                        'RELEASE_ACTOR_IN_IMMUTABILITY_BYPASS',
                        sprintf('Release actor %s is exempt from tag immutability.', json_encode($actor['actor_id'])),
                        'Remove it. Authority to create a release tag is not authority to unmake one; that is the entire reason the tag policy is two rulesets rather than one.',
                        GovernanceRole::ReleaseActor,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<mixed> $ruleset
     * @return list<string>
     */
    private function ruleTypes(array $ruleset): array
    {
        $types = [];

        foreach (is_array($ruleset['rules'] ?? null) ? $ruleset['rules'] : [] as $rule) {
            if (is_array($rule) && is_string($rule['type'] ?? null)) {
                $types[] = $rule['type'];
            }
        }

        return $types;
    }

    private function isPlaceholder(string $value): bool
    {
        $normalised = strtolower(trim($value));

        if ($normalised === '') {
            return true;
        }

        foreach (self::PLACEHOLDER_PREFIXES as $prefix) {
            if (str_starts_with($normalised, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
