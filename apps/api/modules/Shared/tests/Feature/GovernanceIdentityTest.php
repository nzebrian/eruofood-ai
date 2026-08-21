<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Governance\ActivationState;
use EruoFood\Shared\Domain\Governance\GovernanceRole;
use EruoFood\Shared\Domain\Governance\IdentityAssessment;
use EruoFood\Shared\Domain\Governance\IdentityFinding;
use EruoFood\Shared\Domain\Governance\IdentityPolicy;

/**
 * M29-B — the rules that decide whether governance identities may be activated.
 *
 * ## What is actually being defended
 *
 * M29-A left CODEOWNERS inert and every owner an `<OWNER:...>` token. The next
 * failure is obvious once you say it out loud: somebody substitutes handles by
 * hand, gets one wrong, uncomments the rules, and the repository is back to a
 * CODEOWNERS file that reads as configured and resolves to nobody — which is
 * the defect M29-A was opened to remove, restored by the act of fixing it.
 *
 * Every negative case below is one route to that outcome. None of them is
 * hypothetical: a missing role, a blank value, a placeholder carried over, the
 * shipped example used as the live file, a team handle under a user-owned
 * account. Each produces a file that parses, reads as complete, and enforces
 * nothing.
 *
 * ## The two that are not about CODEOWNERS
 *
 * `it refuses a ruleset that restricts creation and enforces immutability at
 * once` and `it refuses a release actor exempt from tag immutability` guard the
 * release path instead. GitHub scopes `bypass_actors` to a whole ruleset, so
 * collapsing M29-A's two tag rulesets into one silently grants the release
 * actor the right to delete and move the tags it creates — and `release.yml`
 * builds and promotes a production container image from exactly those tags.
 *
 * ## What is deliberately not asserted
 *
 * That anything is enforced. {@see ActivationState} has no `Active` case and
 * {@see IdentityAssessment::externalRequirements()} is never empty, and both of
 * those are themselves asserted below. A test proving governance is *on* cannot
 * be written here, because this repository is not the authority.
 */
function m29bRepoRoot(): string
{
    return dirname(base_path(), 2);
}

function m29bPolicy(string $owner = 'nzebrian'): IdentityPolicy
{
    return new IdentityPolicy($owner);
}

/** A configuration with nothing wrong with it, as a base for mutation. */
function m29bValidIdentities(): array
{
    return [
        'repository' => 'nzebrian/eruofood-ai',
        'codeowners' => [
            'MAINTAINERS' => ['handles' => ['@alpha-reviewer']],
            'API' => ['handles' => ['@beta-reviewer']],
            'FINANCE' => ['handles' => ['@gamma-reviewer']],
            'WEB' => ['handles' => ['@delta-reviewer']],
            'MOBILE' => ['handles' => ['@epsilon-reviewer']],
            'PLATFORM' => ['handles' => ['@zeta-reviewer']],
            'GOVERNANCE' => ['handles' => ['@eta-reviewer']],
        ],
        'release_actors' => [
            ['actor_id' => 12345, 'actor_type' => 'Integration', 'bypass_mode' => 'always'],
        ],
    ];
}

/** The two-ruleset tag model exactly as M29-A prepared it. */
function m29bTagRulesets(): array
{
    return [
        [
            'name' => 'production release tags — restricted creation',
            'bypass_actors' => [],
            'rules' => [['type' => 'creation']],
        ],
        [
            'name' => 'production release tags — immutable',
            'bypass_actors' => [],
            'rules' => [['type' => 'deletion'], ['type' => 'non_fast_forward'], ['type' => 'update']],
        ],
    ];
}

/** @return list<string> */
function m29bErrorCodes(IdentityAssessment $assessment): array
{
    return array_map(static fn (IdentityFinding $f): string => $f->code, $assessment->errors());
}

function m29bAssess(?array $identities, string $codeowners = '', ?array $tags = null): IdentityAssessment
{
    return m29bPolicy()->evaluate($identities, $codeowners, $tags ?? m29bTagRulesets());
}

/**
 * Assert a finding code is (or is not) present, with a message naming the input.
 *
 * Not `expect($codes)->toContain($code, $message)`: Pest's `toContain` takes
 * *variadic needles*, so a message passed there becomes a second thing the
 * array must contain. In a loop over inputs that turns a real failure into a
 * confusing one and a passing `->not->toContain` into a tautology.
 *
 * @param list<string> $codes
 */
function m29bExpectCode(array $codes, string $code, string $why, bool $present = true): void
{
    expect(in_array($code, $codes, true))->toBe($present, $why);
}

// -----------------------------------------------------------------------------

describe('the shipped example', function (): void {
    it('exists, parses, and is marked as an example', function (): void {
        $path = m29bRepoRoot().'/.github/governance/identities.example.json';

        expect(file_exists($path))->toBeTrue('identities.example.json is missing');

        $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        expect($doc['_example'] ?? null)->toBeTrue();
    });

    it('names every role the repository needs', function (): void {
        $doc = json_decode(
            (string) file_get_contents(m29bRepoRoot().'/.github/governance/identities.example.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach (GovernanceRole::codeownerRoles() as $role) {
            expect($doc['codeowners'])->toHaveKey($role->value);
        }

        expect($doc['release_actors'])->toBeArray()->not->toBeEmpty();
    });

    it('contains no value that could be mistaken for a real account', function (): void {
        $doc = json_decode(
            (string) file_get_contents(m29bRepoRoot().'/.github/governance/identities.example.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($doc['codeowners'] as $role => $entry) {
            foreach ($entry['handles'] as $handle) {
                expect(str_starts_with((string) $handle, '<EXAMPLE:'))
                    ->toBeTrue("role {$role} names '{$handle}', which reads like a real handle");
            }
        }
    });

    it('is rejected outright when used as the active configuration', function (): void {
        // NEGATIVE CONTROL 4 — example/template identity used as active identity.
        $doc = json_decode(
            (string) file_get_contents(m29bRepoRoot().'/.github/governance/identities.example.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $assessment = m29bAssess($doc);

        expect(m29bErrorCodes($assessment))->toContain('IDENTITY_EXAMPLE_USED_AS_ACTIVE');
        expect($assessment->isReadyForActivation())->toBeFalse();
    });
});

describe('activation state', function (): void {
    it('is unconfigured, not failed, when no identities exist', function (): void {
        // The correct state today. An absent identity file must not turn CI red:
        // nobody has claimed an owner, so nothing is claiming falsely.
        $assessment = m29bAssess(null, (string) file_get_contents(m29bRepoRoot().'/.github/CODEOWNERS'));

        expect($assessment->state)->toBe(ActivationState::Unconfigured);
        expect($assessment->errors())->toBe([]);
        expect($assessment->unresolvedRoles)->toHaveCount(count(GovernanceRole::cases()));
    });

    it('reaches ready-for-activation on a complete configuration', function (): void {
        $assessment = m29bAssess(m29bValidIdentities());

        expect($assessment->state)->toBe(ActivationState::ReadyForActivation);
        expect($assessment->errors())->toBe([]);
        expect($assessment->unresolvedRoles)->toBe([]);
        expect($assessment->resolved)->toHaveCount(count(GovernanceRole::cases()));
    });

    it('has no state meaning "active", and cannot acquire one', function (): void {
        // NEGATIVE CONTROL 9 — active identity configuration treated as live
        // GitHub enforcement. The guarantee is structural rather than
        // behavioural: there is no enum case to reach, so no code path can
        // report enforcement from local files however clean they are.
        $values = array_map(static fn (ActivationState $s): string => $s->value, ActivationState::cases());

        expect($values)->toBe(['unconfigured', 'incomplete', 'ready_for_activation']);

        foreach ($values as $value) {
            expect(str_contains($value, 'active') && $value !== 'ready_for_activation')->toBeFalse();
        }
    });

    it('still defers every GitHub fact on a flawless configuration', function (): void {
        // The same list for a perfect config as for an empty one. If this ever
        // shrinks when identities are supplied, a local file has been allowed to
        // answer a question only GitHub can answer.
        $perfect = m29bAssess(m29bValidIdentities());
        $empty = m29bAssess(null);

        expect($perfect->isReadyForActivation())->toBeTrue();
        expect($perfect->externalRequirements())->toBe($empty->externalRequirements());
        expect($perfect->externalRequirements())->not->toBeEmpty();
    });
});

describe('role completeness', function (): void {
    it('rejects a configuration missing a required role', function (): void {
        // NEGATIVE CONTROL 1 — missing required role.
        foreach (GovernanceRole::codeownerRoles() as $role) {
            $identities = m29bValidIdentities();
            unset($identities['codeowners'][$role->value]);

            $assessment = m29bAssess($identities);

            expect(m29bErrorCodes($assessment))
                ->toContain('IDENTITY_ROLE_MISSING');
            expect($assessment->unresolvedRoles)->toContain($role->value);
            expect($assessment->isReadyForActivation())->toBeFalse();
        }
    });

    it('rejects an empty identity', function (): void {
        // NEGATIVE CONTROL 2 — empty identity. Two shapes: no handles at all,
        // and a handle that is the empty string. Both route review to nobody
        // while the file reads as configured.
        $noHandles = m29bValidIdentities();
        $noHandles['codeowners']['FINANCE']['handles'] = [];

        expect(m29bErrorCodes(m29bAssess($noHandles)))->toContain('IDENTITY_EMPTY');

        $blank = m29bValidIdentities();
        $blank['codeowners']['FINANCE']['handles'] = ['   '];

        expect(m29bErrorCodes(m29bAssess($blank)))->toContain('IDENTITY_EMPTY');
    });

    it('rejects an unknown role rather than ignoring it', function (): void {
        // NEGATIVE CONTROL 8 — unknown identity role. Ignoring it is the
        // dangerous option: `FINANC` leaves FINANCE unfilled while the file
        // looks complete, and nothing routes review on the money-moving paths.
        $identities = m29bValidIdentities();
        $identities['codeowners']['FINANC'] = ['handles' => ['@typo-reviewer']];

        expect(m29bErrorCodes(m29bAssess($identities)))->toContain('IDENTITY_ROLE_UNKNOWN');
    });

    it('rejects a placeholder left in an active role', function (): void {
        // NEGATIVE CONTROL 3 (identity side) — unresolved placeholder.
        foreach (['<OWNER:FINANCE>', '<EXAMPLE:finance-reviewer>', 'change_me', 'TBD', '@your-team'] as $placeholder) {
            $identities = m29bValidIdentities();
            $identities['codeowners']['FINANCE']['handles'] = [$placeholder];

            expect(m29bErrorCodes(m29bAssess($identities)))
                ->toContain('IDENTITY_PLACEHOLDER_ACTIVE');
        }
    });
});

describe('handle syntax', function (): void {
    it('rejects handles GitHub will not accept', function (): void {
        // NEGATIVE CONTROL 5 — invalid GitHub username syntax. Every one of
        // these is rejected by GitHub, and every one of them sits happily in a
        // JSON file until somebody tries to merge.
        $invalid = [
            '@-leading-hyphen',
            '@trailing-hyphen-',
            '@double--hyphen',
            'no-at-sign',
            '@has spaces',
            '@toolong'.str_repeat('x', 40),
            '@',
        ];

        foreach ($invalid as $handle) {
            $identities = m29bValidIdentities();
            $identities['codeowners']['API']['handles'] = [$handle];

            m29bExpectCode(
                m29bErrorCodes(m29bAssess($identities)),
                'IDENTITY_SYNTAX_INVALID',
                "'{$handle}' was accepted",
            );
        }
    });

    it('accepts the handle forms GitHub actually resolves', function (): void {
        foreach (['@a', '@alpha-reviewer', '@a1-b2-c3', 'person@example.com'] as $handle) {
            $identities = m29bValidIdentities();
            $identities['codeowners']['API']['handles'] = [$handle];

            m29bExpectCode(
                m29bErrorCodes(m29bAssess($identities)),
                'IDENTITY_SYNTAX_INVALID',
                "'{$handle}' was rejected",
                present: false,
            );
        }
    });

    it('warns that a team handle cannot resolve under a user-owned repository', function (): void {
        // The M29-A defect itself, caught one layer earlier. Syntactically legal,
        // impossible here, and the reason CODEOWNERS named six phantom teams.
        $identities = m29bValidIdentities();
        $identities['codeowners']['API']['handles'] = ['@eruofood/backend'];

        $assessment = m29bAssess($identities);
        $codes = array_map(static fn (IdentityFinding $f): string => $f->code, $assessment->warnings());

        expect($codes)->toContain('IDENTITY_TEAM_REQUIRES_ORGANIZATION');
    });

    it('refuses to let the repository owner review their own financial changes', function (): void {
        // GitHub forbids approving your own pull request, so an owner who
        // authors every change is not a second pair of eyes on the money-moving
        // paths — they are no pair of eyes, silently.
        $identities = m29bValidIdentities();
        $identities['codeowners']['FINANCE']['handles'] = ['@nzebrian'];

        expect(m29bErrorCodes(m29bAssess($identities)))->toContain('FINANCE_OWNER_IS_REPOSITORY_OWNER');

        // Elsewhere it is a legitimate single-maintainer arrangement, so it
        // warns and asks for a decision rather than blocking.
        $elsewhere = m29bValidIdentities();
        $elsewhere['codeowners']['WEB']['handles'] = ['@nzebrian'];

        $assessment = m29bAssess($elsewhere);

        expect(m29bErrorCodes($assessment))->not->toContain('FINANCE_OWNER_IS_REPOSITORY_OWNER');
        expect(array_map(static fn (IdentityFinding $f): string => $f->code, $assessment->warnings()))
            ->toContain('IDENTITY_IS_REPOSITORY_OWNER');
    });
});

describe('the release actor', function (): void {
    it('rejects a configuration with no release actor', function (): void {
        // NEGATIVE CONTROL 6 — missing RELEASE_ACTOR.
        $missing = m29bValidIdentities();
        unset($missing['release_actors']);

        expect(m29bErrorCodes(m29bAssess($missing)))->toContain('RELEASE_ACTOR_MISSING');

        $empty = m29bValidIdentities();
        $empty['release_actors'] = [];

        expect(m29bErrorCodes(m29bAssess($empty)))->toContain('RELEASE_ACTOR_MISSING');
    });

    it('requires a numeric actor id, not a handle', function (): void {
        // Bypass actors are numeric ids. A handle here validates in any
        // schema that treats all identities alike, and is rejected by the API.
        foreach (['@somebody', '12345', 0, -1, null, 1.5] as $id) {
            $identities = m29bValidIdentities();
            $identities['release_actors'][0]['actor_id'] = $id;

            m29bExpectCode(
                m29bErrorCodes(m29bAssess($identities)),
                'RELEASE_ACTOR_SYNTAX_INVALID',
                'actor_id '.json_encode($id).' was accepted',
            );
        }
    });

    it('rejects a placeholder actor id', function (): void {
        $identities = m29bValidIdentities();
        $identities['release_actors'][0]['actor_id'] = '<EXAMPLE:numeric-actor-id>';

        expect(m29bErrorCodes(m29bAssess($identities)))->toContain('RELEASE_ACTOR_PLACEHOLDER_ACTIVE');
    });

    it('refuses release authority granted to a category rather than an actor', function (): void {
        // production-tags-ruleset.json's actor_placeholder_contract forbids
        // this: the membership of "organization admin" changes without anybody
        // deciding that release authority should change with it.
        $identities = m29bValidIdentities();
        $identities['release_actors'][0]['actor_type'] = 'OrganizationAdmin';

        expect(m29bErrorCodes(m29bAssess($identities)))->toContain('RELEASE_ACTOR_NOT_A_NAMED_GRANT');
    });

    it('rejects an unknown actor type or bypass mode', function (): void {
        $type = m29bValidIdentities();
        $type['release_actors'][0]['actor_type'] = 'Wildcard';

        expect(m29bErrorCodes(m29bAssess($type)))->toContain('RELEASE_ACTOR_SYNTAX_INVALID');

        $mode = m29bValidIdentities();
        $mode['release_actors'][0]['bypass_mode'] = 'sometimes';

        expect(m29bErrorCodes(m29bAssess($mode)))->toContain('RELEASE_ACTOR_SYNTAX_INVALID');
    });
});

describe('CODEOWNERS activation safety', function (): void {
    it('accepts the current file, in which every rule is commented out', function (): void {
        $assessment = m29bAssess(null, (string) file_get_contents(m29bRepoRoot().'/.github/CODEOWNERS'));

        expect(m29bErrorCodes($assessment))->not->toContain('CODEOWNERS_PLACEHOLDER_ACTIVE');
    });

    it('rejects an unresolved placeholder on an active rule', function (): void {
        // NEGATIVE CONTROL 3 — unresolved placeholder in active CODEOWNERS.
        // The commented form is the M29-A hand-over state and stays valid; the
        // uncommented form is the defect and must fail.
        $active = "# a comment\n/apps/api/modules/Payments/ <OWNER:FINANCE>\n";

        expect(m29bErrorCodes(m29bAssess(null, $active)))->toContain('CODEOWNERS_PLACEHOLDER_ACTIVE');

        $commented = "# /apps/api/modules/Payments/ <OWNER:FINANCE>\n";

        expect(m29bErrorCodes(m29bAssess(null, $commented)))->not->toContain('CODEOWNERS_PLACEHOLDER_ACTIVE');
    });

    it('preserves the M29-A ownership domains and their ordering', function (): void {
        // The resolver substitutes; it must not redesign. Ordering is load-bearing
        // in CODEOWNERS — last match wins, so the catch-all is first and
        // /.github/CODEOWNERS is last so governance cannot be widened by editing
        // the file that enforces it.
        $body = (string) file_get_contents(m29bRepoRoot().'/.github/CODEOWNERS');

        $domains = [
            '* <OWNER:MAINTAINERS>',
            '/apps/api/ <OWNER:API>',
            '/apps/api/modules/Payments/ <OWNER:FINANCE>',
            '/apps/api/config/payments.php <OWNER:FINANCE>',
            '/apps/web/ <OWNER:WEB>',
            '/apps/mobile/ <OWNER:MOBILE>',
            '/packages/api-contracts/ <OWNER:API> <OWNER:WEB>',
            '/infra/ <OWNER:PLATFORM>',
            '/.github/workflows/ <OWNER:PLATFORM>',
            '/.github/governance/ <OWNER:GOVERNANCE>',
            '/.github/CODEOWNERS <OWNER:GOVERNANCE>',
        ];

        $previous = -1;

        foreach ($domains as $domain) {
            $at = strpos($body, $domain);

            expect($at)->not->toBeFalse("ownership domain lost: {$domain}");
            expect($at)->toBeGreaterThan($previous, "ownership order changed at: {$domain}");
            $previous = (int) $at;
        }

        // Last, so it wins.
        expect(strpos($body, '/.github/CODEOWNERS <OWNER:GOVERNANCE>'))
            ->toBeGreaterThan((int) strpos($body, '/.github/governance/ <OWNER:GOVERNANCE>'));
    });

    it('covers every CODEOWNERS token with a role, and every role with a token', function (): void {
        // Drift in either direction is silent. A token with no role renders as
        // itself; a role with no token is configured and unused.
        $body = (string) file_get_contents(m29bRepoRoot().'/.github/CODEOWNERS');

        preg_match_all('/<OWNER:([A-Z_]+)>/', $body, $matches);
        $inFile = array_values(array_unique($matches[1]));
        sort($inFile);

        $inEnum = array_map(static fn (GovernanceRole $r): string => $r->value, GovernanceRole::codeownerRoles());
        sort($inEnum);

        expect($inFile)->toBe($inEnum);
        expect(str_contains($body, GovernanceRole::ReleaseActor->token()))
            ->toBeFalse('RELEASE_ACTOR is a ruleset bypass actor, not a code owner');
    });
});

describe('tag safety', function (): void {
    it('accepts the two-ruleset model as prepared', function (): void {
        expect(m29bErrorCodes(m29bAssess(m29bValidIdentities())))->toBe([]);
    });

    it('refuses a ruleset that restricts creation and enforces immutability at once', function (): void {
        // NEGATIVE CONTROL 7 (structural half) — the collapse. bypass_actors is
        // scoped to a whole ruleset, so a single ruleset doing both jobs must
        // exempt the release actor from deletion in order to let it create.
        $collapsed = [[
            'name' => 'production release tags',
            'bypass_actors' => [],
            'rules' => [['type' => 'creation'], ['type' => 'deletion'], ['type' => 'update']],
        ]];

        expect(m29bErrorCodes(m29bAssess(m29bValidIdentities(), '', $collapsed)))
            ->toContain('TAG_RULESET_COLLAPSED');
    });

    it('refuses any bypass actor on the immutability ruleset', function (): void {
        $tags = m29bTagRulesets();
        $tags[1]['bypass_actors'] = [['actor_id' => 99, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always']];

        expect(m29bErrorCodes(m29bAssess(m29bValidIdentities(), '', $tags)))
            ->toContain('TAG_IMMUTABILITY_BYPASS_PRESENT');
    });

    it('refuses a release actor exempt from tag immutability', function (): void {
        // NEGATIVE CONTROL 7 — release actor added to tag immutability bypass.
        // Distinct from the check above: this names the specific mistake of
        // granting creation and immutability bypass to the same actor across two
        // files, each of which looks correct read alone.
        $identities = m29bValidIdentities();
        $tags = m29bTagRulesets();
        $tags[1]['bypass_actors'] = [
            ['actor_id' => $identities['release_actors'][0]['actor_id'], 'actor_type' => 'Integration', 'bypass_mode' => 'always'],
        ];

        $codes = m29bErrorCodes(m29bAssess($identities, '', $tags));

        expect($codes)->toContain('RELEASE_ACTOR_IN_IMMUTABILITY_BYPASS');
        expect($codes)->toContain('TAG_IMMUTABILITY_BYPASS_PRESENT');
    });

    it('holds the invariant against the real artifact on disk', function (): void {
        $doc = json_decode(
            (string) file_get_contents(m29bRepoRoot().'/.github/governance/production-tags-ruleset.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect(m29bErrorCodes(m29bAssess(null, '', $doc['rulesets'])))->toBe([]);

        foreach ($doc['rulesets'] as $ruleset) {
            $types = array_column($ruleset['rules'], 'type');

            if (array_intersect(['deletion', 'non_fast_forward', 'update'], $types) !== []) {
                expect($ruleset['bypass_actors'])->toBe([], 'immutability must never carry a bypass actor');
            }
        }
    });
});
