<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Governance\GovernanceRole;
use EruoFood\Shared\Domain\Governance\IdentityFinding;
use EruoFood\Shared\Domain\Governance\IdentityPolicy;
use EruoFood\Shared\Domain\Governance\OwnershipDeclaration;
use EruoFood\Shared\Domain\Governance\OwnershipMode;

/**
 * M29-I — governance that tells the truth about how many people there are.
 *
 * ## The problem this solves
 *
 * M29-A prepared a ruleset assuming more than one human: one approving review,
 * code-owner review required, FINANCE forbidden from being the repository
 * owner. M29-H then established that the repository has exactly one account
 * with access, and that account authors every commit.
 *
 * Applied unchanged, that policy does not produce strong governance. It
 * produces a repository nobody can merge into — GitHub forbids approving your
 * own pull request — and a code-owner requirement pointed at a CODEOWNERS file
 * in which every rule is commented out, which is the M29-A defect restored.
 *
 * ## What these tests defend
 *
 * The line between *deferred and recorded* and *reported as satisfied*. A mode
 * that quietly relaxed a check would be worse than no mode: it would put the
 * word "governance" on a configuration nobody had examined.
 *
 * So the assertions below are mostly about what SOLE_OWNER **cannot** do —
 * it cannot drop a status check, cannot open a bypass, cannot make the finance
 * gap disappear from the findings, and cannot be reached by a mode string
 * nobody implemented.
 *
 * ## The one that matters most
 *
 * `it refuses an AI assistant as a participant`. Claude and ChatGPT wrote much
 * of this governance. A synthetic second reviewer would satisfy every other
 * check in the repository — syntax, resolvability, not-the-owner, mode
 * consistency — and provide none of the review it appears to provide. It is the
 * one failure mode where a green report would be actively misleading rather
 * than merely incomplete.
 */
function m29iRepoRoot(): string
{
    return dirname(base_path(), 2);
}

function m29iGovernanceJson(string $file): array
{
    $path = m29iRepoRoot().'/.github/governance/'.$file;

    expect(file_exists($path))->toBeTrue("missing governance artifact: {$file}");

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/** A declaration with nothing wrong with it, as a base for mutation. */
function m29iDeclaration(): array
{
    return [
        'mode' => 'SOLE_OWNER',
        'repository' => 'nzebrian/eruofood-ai',
        'repository_owner' => 'nzebrian',
        'human_participants' => ['nzebrian'],
    ];
}

/** @return list<string> */
function m29iCodes(array $findings): array
{
    return array_map(static fn (IdentityFinding $f): string => $f->code, $findings);
}

/**
 * Assert a finding code is present, with a message naming the offending input.
 *
 * Not `expect($codes)->toContain($code, $message)`: Pest's `toContain` takes
 * *variadic needles*, so a message passed there becomes a second value the
 * array must contain. In a loop over inputs that turns a real failure into a
 * baffling one, and turns a passing `->not->toContain` into a tautology.
 *
 * @param list<string> $codes
 */
function m29iExpectCode(array $codes, string $code, string $why): void
{
    expect(in_array($code, $codes, true))->toBeTrue($why);
}

function m29iRuleOfType(array $ruleset, string $type): ?array
{
    foreach ($ruleset['rules'] ?? [] as $rule) {
        if (($rule['type'] ?? null) === $type) {
            return $rule;
        }
    }

    return null;
}

// -----------------------------------------------------------------------------

describe('the shipped declaration', function (): void {
    it('declares SOLE_OWNER with one real human', function (): void {
        $doc = m29iGovernanceJson('ownership.json');

        expect($doc['mode'])->toBe('SOLE_OWNER');
        expect($doc['repository_owner'])->toBe('nzebrian');
        expect($doc['human_participants'])->toBe(['nzebrian']);
    });

    it('parses into a usable declaration', function (): void {
        $d = OwnershipDeclaration::fromArray(m29iGovernanceJson('ownership.json'));

        expect($d->isUsable())->toBeTrue();
        expect($d->mode)->toBe(OwnershipMode::SoleOwner);
        expect($d->errors())->toBe([]);
    });

    it('records what is deferred, with a reason and a resolution', function (): void {
        // Deferred without a route back is just switched off. Each entry has to
        // say what would resolve it, or the mode becomes permanent by inertia.
        $doc = m29iGovernanceJson('ownership.json');

        $controls = array_column($doc['deferred_controls'], 'control');

        expect($controls)->toContain('independent human review');
        expect($controls)->toContain('CODEOWNERS enforcement');
        expect($controls)->toContain('finance four-eyes review');

        foreach ($doc['deferred_controls'] as $entry) {
            expect($entry['state'])->toBe('DEFERRED');
            expect($entry['reason'] ?? '')->not->toBe('');
            expect($entry['resolves_when'] ?? '')->not->toBe('');
        }
    });

    it('agrees with the repository the other artifacts describe', function (): void {
        $owner = explode('/', m29iGovernanceJson('production-tags-ruleset.json')['_meta']['applies_to'])[0];

        expect(m29iGovernanceJson('ownership.json')['repository_owner'])->toBe($owner);
    });
});

describe('the mode', function (): void {
    it('offers exactly two modes and no default fallback', function (): void {
        expect(array_map(static fn (OwnershipMode $m): string => $m->value, OwnershipMode::cases()))
            ->toBe(['SOLE_OWNER', 'MULTI_PERSON']);

        expect(OwnershipMode::tryFrom('SOMETHING_ELSE'))->toBeNull();
    });

    it('rejects a mode nobody implemented rather than guessing', function (): void {
        // Falling back to a default would pick a review policy on the reader's
        // behalf, silently.
        $d = OwnershipDeclaration::fromArray(['mode' => 'RELAXED'] + m29iDeclaration());

        expect(m29iCodes($d->errors()))->toContain('OWNERSHIP_MODE_UNKNOWN');
        expect($d->isUsable())->toBeFalse();
    });

    it('states plainly which controls are not active', function (): void {
        $lines = implode("\n", OwnershipMode::SoleOwner->summaryLines());

        expect($lines)->toContain('SOLE_OWNER MODE');
        expect($lines)->toContain('Automated controls:      ACTIVE');
        expect($lines)->toContain('Independent human review: DEFERRED');
        expect($lines)->toContain('CODEOWNERS enforcement:   DEFERRED');
        expect($lines)->toContain('Finance four-eyes review: DEFERRED');
        expect($lines)->toContain('one real human owner');
    });

    it('requires zero approvals under SOLE_OWNER and one under MULTI_PERSON', function (): void {
        // Exact, not a minimum. Above zero blocks every merge on a one-human
        // repository; zero under MULTI_PERSON discards the requirement the mode
        // exists to assert.
        expect(OwnershipMode::SoleOwner->requiredApprovingReviewCount())->toBe(0);
        expect(OwnershipMode::MultiPerson->requiredApprovingReviewCount())->toBe(1);

        expect(OwnershipMode::SoleOwner->supportsCodeOwnerReview())->toBeFalse();
        expect(OwnershipMode::MultiPerson->supportsCodeOwnerReview())->toBeTrue();

        expect(OwnershipMode::SoleOwner->supportsIndependentReview())->toBeFalse();
        expect(OwnershipMode::MultiPerson->supportsIndependentReview())->toBeTrue();
    });
});

describe('synthetic participants', function (): void {
    it('refuses an AI assistant as a participant', function (): void {
        foreach (['claude', 'ChatGPT', '@openai', 'anthropic', 'copilot', 'dependabot[bot]'] as $handle) {
            $d = OwnershipDeclaration::fromArray(['human_participants' => [$handle]] + m29iDeclaration());

            m29iExpectCode(
                m29iCodes($d->errors()),
                'OWNERSHIP_PARTICIPANT_NOT_HUMAN',
                "'{$handle}' was accepted as a human",
            );
        }
    });

    it('refuses an AI assistant as a code owner', function (): void {
        // The same guard one layer down. An assistant handle would otherwise
        // pass every identity check: it is syntactically valid, it is not a
        // placeholder, and it is not the repository owner.
        $identities = [
            'codeowners' => [
                'MAINTAINERS' => ['handles' => ['@alpha-reviewer']],
                'API' => ['handles' => ['@beta-reviewer']],
                'FINANCE' => ['handles' => ['@claude']],
                'WEB' => ['handles' => ['@delta-reviewer']],
                'MOBILE' => ['handles' => ['@epsilon-reviewer']],
                'PLATFORM' => ['handles' => ['@zeta-reviewer']],
                'GOVERNANCE' => ['handles' => ['@eta-reviewer']],
            ],
            'release_actors' => [['actor_id' => 1, 'actor_type' => 'Integration', 'bypass_mode' => 'always']],
        ];

        $assessment = (new IdentityPolicy('nzebrian', OwnershipMode::MultiPerson))->evaluate($identities, '', []);

        expect(m29iCodes($assessment->errors()))->toContain('IDENTITY_NOT_HUMAN');
        expect($assessment->isReadyForActivation())->toBeFalse();
    });

    it('does not reject a real username that merely contains a substring', function (): void {
        // A governance check that rejects a real contributor is its own failure.
        foreach (['@robotics-dev', '@claudia', '@bottomley', '@gptaylor'] as $handle) {
            expect(OwnershipDeclaration::isNonHuman($handle))
                ->toBeFalse("'{$handle}' was wrongly rejected as non-human");
        }
    });
});

describe('mode and participants must agree', function (): void {
    it('refuses MULTI_PERSON with only one participant', function (): void {
        $d = OwnershipDeclaration::fromArray(['mode' => 'MULTI_PERSON'] + m29iDeclaration());

        expect(m29iCodes($d->errors()))->toContain('OWNERSHIP_MODE_CONTRADICTS_PARTICIPANTS');
    });

    it('accepts MULTI_PERSON once a second real human exists', function (): void {
        $d = OwnershipDeclaration::fromArray([
            'mode' => 'MULTI_PERSON',
            'human_participants' => ['nzebrian', 'second-human'],
        ] + m29iDeclaration());

        expect($d->isUsable())->toBeTrue();
        expect($d->mode)->toBe(OwnershipMode::MultiPerson);
    });

    it('refuses a declaration with nobody in it', function (): void {
        $d = OwnershipDeclaration::fromArray(['human_participants' => []] + m29iDeclaration());

        expect(m29iCodes($d->errors()))->toContain('OWNERSHIP_NO_HUMAN_PARTICIPANTS');
    });

    it('refuses an absent declaration rather than defaulting', function (): void {
        $d = OwnershipDeclaration::fromArray(null);

        expect(m29iCodes($d->errors()))->toContain('OWNERSHIP_DECLARATION_MISSING');
        expect($d->isUsable())->toBeFalse();
    });
});

describe('the finance deferral', function (): void {
    /** @return list<string> */
    $financeCodes = function (OwnershipMode $mode): array {
        $identities = [
            'codeowners' => [
                'MAINTAINERS' => ['handles' => ['@alpha-reviewer']],
                'API' => ['handles' => ['@beta-reviewer']],
                'FINANCE' => ['handles' => ['@nzebrian']],
                'WEB' => ['handles' => ['@delta-reviewer']],
                'MOBILE' => ['handles' => ['@epsilon-reviewer']],
                'PLATFORM' => ['handles' => ['@zeta-reviewer']],
                'GOVERNANCE' => ['handles' => ['@eta-reviewer']],
            ],
            'release_actors' => [['actor_id' => 1, 'actor_type' => 'Integration', 'bypass_mode' => 'always']],
        ];

        $a = (new IdentityPolicy('nzebrian', $mode))->evaluate($identities, '', []);

        return array_map(
            static fn (IdentityFinding $f): string => $f->code,
            array_merge($a->errors(), $a->warnings()),
        );
    };

    it('is a hard error under MULTI_PERSON', function () use ($financeCodes): void {
        expect($financeCodes(OwnershipMode::MultiPerson))->toContain('FINANCE_OWNER_IS_REPOSITORY_OWNER');
    });

    it('is recorded, not silently dropped, under SOLE_OWNER', function () use ($financeCodes): void {
        // The distinction the whole milestone turns on. The finding still fires
        // and still names the gap; it just no longer blocks a repository where
        // the gap cannot be closed.
        $codes = $financeCodes(OwnershipMode::SoleOwner);

        expect($codes)->toContain('FINANCE_FOUR_EYES_DEFERRED');
        expect($codes)->not->toContain('FINANCE_OWNER_IS_REPOSITORY_OWNER');
    });

    it('says out loud that four-eyes review is not active', function (): void {
        $identities = [
            'codeowners' => array_fill_keys(
                array_map(static fn (GovernanceRole $r): string => $r->value, GovernanceRole::codeownerRoles()),
                ['handles' => ['@nzebrian']],
            ),
            'release_actors' => [['actor_id' => 1, 'actor_type' => 'Integration', 'bypass_mode' => 'always']],
        ];

        $a = (new IdentityPolicy('nzebrian', OwnershipMode::SoleOwner))->evaluate($identities, '', []);

        $deferral = array_values(array_filter(
            $a->warnings(),
            static fn (IdentityFinding $f): bool => $f->code === 'FINANCE_FOUR_EYES_DEFERRED',
        ));

        expect($deferral)->toHaveCount(1);
        expect($deferral[0]->summary)->toContain('NOT ACTIVE');
        expect($deferral[0]->remedy)->toContain('second real human');
    });

    it('defaults to the strict policy when no mode is passed', function (): void {
        // A caller that forgets the mode must get MULTI_PERSON, not the
        // permissive one. Forgetting should fail closed.
        $identities = [
            'codeowners' => array_fill_keys(
                array_map(static fn (GovernanceRole $r): string => $r->value, GovernanceRole::codeownerRoles()),
                ['handles' => ['@nzebrian']],
            ),
            'release_actors' => [['actor_id' => 1, 'actor_type' => 'Integration', 'bypass_mode' => 'always']],
        ];

        $a = (new IdentityPolicy('nzebrian'))->evaluate($identities, '', []);

        expect(m29iCodes($a->errors()))->toContain('FINANCE_OWNER_IS_REPOSITORY_OWNER');
    });
});

describe('the sole-owner ruleset', function (): void {
    it('relaxes only the parameters that need a second human', function (): void {
        $multi = m29iGovernanceJson('main-ruleset.json')['rulesets'][0];
        $sole = m29iGovernanceJson('main-ruleset.sole-owner.json')['rulesets'][0];

        $mp = m29iRuleOfType($multi, 'pull_request')['parameters'];
        $sp = m29iRuleOfType($sole, 'pull_request')['parameters'];

        expect($sp['required_approving_review_count'])->toBe(0);
        expect($sp['require_code_owner_review'])->toBeFalse();
        expect($sp['require_last_push_approval'])->toBeFalse();

        // Everything else in the pull_request rule is untouched.
        foreach (array_keys($mp) as $key) {
            if (in_array($key, ['required_approving_review_count', 'require_code_owner_review', 'require_last_push_approval'], true)) {
                continue;
            }

            expect($sp[$key] ?? null)->toBe($mp[$key], "pull_request.{$key} was changed and is not a review parameter");
        }
    });

    it('keeps every automated gate identical to the multi-person policy', function (): void {
        // The claim "automated controls: ACTIVE" has to be true, not asserted.
        $multi = m29iGovernanceJson('main-ruleset.json')['rulesets'][0];
        $sole = m29iGovernanceJson('main-ruleset.sole-owner.json')['rulesets'][0];

        expect(m29iRuleOfType($sole, 'required_status_checks'))
            ->toBe(m29iRuleOfType($multi, 'required_status_checks'));

        expect(array_column($sole['rules'], 'type'))->toBe(array_column($multi['rules'], 'type'));
        expect($sole['conditions'])->toBe($multi['conditions']);
        expect($sole['target'])->toBe($multi['target']);
        expect($sole['enforcement'])->toBe($multi['enforcement']);
    });

    it('carries no bypass actor', function (): void {
        // A sole owner with admin can disable a ruleset deliberately and
        // visibly. A standing bypass would let them merge past a failing
        // financial gate with no trace at all.
        expect(m29iGovernanceJson('main-ruleset.sole-owner.json')['rulesets'][0]['bypass_actors'])->toBe([]);
    });

    it('still forbids deletion and force-push of main', function (): void {
        $sole = m29iGovernanceJson('main-ruleset.sole-owner.json')['rulesets'][0];

        expect(m29iRuleOfType($sole, 'deletion'))->not->toBeNull();
        expect(m29iRuleOfType($sole, 'non_fast_forward'))->not->toBeNull();
        expect(m29iRuleOfType($sole, 'pull_request'))->not->toBeNull();
    });

    it('is the artifact the declared mode selects', function (): void {
        expect(OwnershipMode::SoleOwner->mainRulesetArtifact())->toBe('main-ruleset.sole-owner.json');
        expect(OwnershipMode::MultiPerson->mainRulesetArtifact())->toBe('main-ruleset.json');

        $declared = OwnershipDeclaration::fromArray(m29iGovernanceJson('ownership.json'));

        expect(file_exists(m29iRepoRoot().'/.github/governance/'.$declared->mode->mainRulesetArtifact()))->toBeTrue();
    });
});

describe('CODEOWNERS stays inert under SOLE_OWNER', function (): void {
    it('has no active rule while enforcement is deferred', function (): void {
        // Requiring code-owner review against a file with no active rules is the
        // M29-A defect. The sole-owner ruleset sets require_code_owner_review to
        // false for exactly this reason, and this asserts the premise still holds.
        $body = (string) file_get_contents(m29iRepoRoot().'/.github/CODEOWNERS');

        $active = array_values(array_filter(
            explode("\n", $body),
            static fn (string $l): bool => trim($l) !== '' && ! str_starts_with(trim($l), '#'),
        ));

        expect($active)->toBe([]);
        expect($body)->toContain('<OWNER:');
    });
});
