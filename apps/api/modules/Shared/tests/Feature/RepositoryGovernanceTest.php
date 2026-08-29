<?php

declare(strict_types=1);

/**
 * M29-A — the governance artifacts, held to the policy they claim to encode.
 *
 * ## Why a test suite for JSON files
 *
 * Because the failure mode this milestone exists to fix was a *file that looked
 * right*. `.github/CODEOWNERS` named six teams for months; all eight owner
 * references were unresolvable, and nothing in the repository noticed. The
 * artifacts under `.github/governance/` describe protections that GitHub is not
 * yet enforcing, which makes them exactly the kind of thing that rots quietly:
 * plausible, unexecuted, and trusted by the next person who opens them.
 *
 * So the properties that matter are asserted rather than assumed — and the
 * assertions are about *policy*, not shape. A ruleset that parses but no longer
 * forbids force-pushes should fail here.
 *
 * ## The one that will save somebody
 *
 * `it refuses a path filter on any required workflow's pull_request trigger`.
 * GitHub treats a required check that never reports as pending, not satisfied.
 * Re-adding `paths:` to one of these workflows would not break CI — it would
 * make every unrelated pull request unmergeable, forever, with no error
 * message anywhere. That is a hard failure to diagnose from the symptom.
 */
function repoRoot(): string
{
    return dirname(base_path(), 2);
}

function governanceJson(string $file): array
{
    $path = repoRoot().'/.github/governance/'.$file;

    expect(file_exists($path))->toBeTrue("missing governance artifact: {$file}");

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed>|null */
function ruleNamed(array $ruleset, string $type): ?array
{
    foreach ($ruleset['rules'] ?? [] as $rule) {
        if (($rule['type'] ?? null) === $type) {
            return $rule;
        }
    }

    return null;
}

describe('governance artifacts', function (): void {
    it('ships every artifact the runbooks reference', function (string $file): void {
        expect(file_exists(repoRoot().'/.github/governance/'.$file))->toBeTrue();
    })->with([
        'main-ruleset.json',
        'production-tags-ruleset.json',
        'required-checks.json',
        'README.md',
        'APPLY_GOVERNANCE.md',
        'VERIFY_GOVERNANCE.md',
        'BREAK_GLASS.md',
    ]);

    it('keeps every JSON artifact parseable', function (string $file): void {
        expect(governanceJson($file))->toBeArray()->not->toBeEmpty();
    })->with(['main-ruleset.json', 'production-tags-ruleset.json', 'required-checks.json']);
});

describe('the main ruleset', function (): void {
    beforeEach(function (): void {
        $this->main = governanceJson('main-ruleset.json')['rulesets'][0] ?? null;
        expect($this->main)->not->toBeNull();
    });

    it('targets refs/heads/main and is active', function (): void {
        expect($this->main['target'])->toBe('branch')
            ->and($this->main['enforcement'])->toBe('active')
            ->and($this->main['conditions']['ref_name']['include'])->toContain('refs/heads/main');
    });

    it('blocks deletion and force-pushes', function (string $type): void {
        expect(ruleNamed($this->main, $type))->not->toBeNull();
    })->with(['deletion', 'non_fast_forward']);

    it('requires a pull request with all four review protections', function (): void {
        $p = ruleNamed($this->main, 'pull_request')['parameters'] ?? [];

        expect($p['required_approving_review_count'] ?? null)->toBeInt()
            ->and($p['dismiss_stale_reviews_on_push'] ?? null)->toBeTrue()
            ->and($p['require_code_owner_review'] ?? null)->toBeTrue()
            ->and($p['require_last_push_approval'] ?? null)->toBeTrue();
    });

    it('requires status checks strictly', function (): void {
        $p = ruleNamed($this->main, 'required_status_checks')['parameters'] ?? [];

        expect($p['strict_required_status_checks_policy'] ?? null)->toBeTrue()
            ->and($p['required_status_checks'] ?? [])->not->toBeEmpty();
    });

    it('grants nobody a standing bypass', function (): void {
        // The single line that decides whether any of the above means anything.
        expect($this->main['bypass_actors'])->toBe([]);
    });

    it('does not require linear history, which would forbid the merge workflow', function (): void {
        // docs/ROLLBACK_PLAN.md §7 reverts the M27 merge with `git revert -m 1`.
        expect(ruleNamed($this->main, 'required_linear_history'))->toBeNull();
    });

    it('does not yet require signatures, which nothing is configured to produce', function (): void {
        expect(ruleNamed($this->main, 'required_signatures'))->toBeNull();
    });
});

describe('the production tag rulesets', function (): void {
    beforeEach(function (): void {
        $this->tags = governanceJson('production-tags-ruleset.json')['rulesets'] ?? [];
    });

    it('splits creation from immutability into two rulesets', function (): void {
        // GitHub scopes bypass_actors to a whole ruleset. Release actors who may
        // create a tag must not thereby be able to delete one.
        expect($this->tags)->toHaveCount(2);
    });

    it('targets refs/tags/v* actively in both', function (): void {
        foreach ($this->tags as $rs) {
            expect($rs['target'])->toBe('tag')
                ->and($rs['enforcement'])->toBe('active')
                ->and($rs['conditions']['ref_name']['include'])->toContain('refs/tags/v*');
        }
    });

    it('restricts who may create a release tag', function (): void {
        $withCreation = array_values(array_filter(
            $this->tags,
            static fn (array $rs): bool => ruleNamed($rs, 'creation') !== null,
        ));

        expect($withCreation)->toHaveCount(1);
    });

    it('makes release tags immutable', function (string $type): void {
        $found = array_values(array_filter(
            $this->tags,
            static fn (array $rs): bool => ruleNamed($rs, $type) !== null,
        ));

        expect($found)->not->toBeEmpty("no ruleset carries a {$type} rule");
    })->with(['deletion', 'non_fast_forward', 'update']);

    it('never lets the immutability ruleset carry a bypass actor', function (): void {
        foreach ($this->tags as $rs) {
            if (ruleNamed($rs, 'deletion') !== null) {
                expect($rs['bypass_actors'])->toBe([], "'{$rs['name']}' grants a deletion bypass");
            }
        }
    });

    it('records that release actor identities are still unresolved', function (): void {
        // The artifact must not imply the policy is complete. Identities are an
        // administrator input; inventing one would be worse than leaving it open.
        $meta = governanceJson('production-tags-ruleset.json')['_meta'] ?? [];

        expect($meta['unresolved_administrator_inputs'] ?? [])->not->toBeEmpty();
    });
});

describe('required checks', function (): void {
    beforeEach(function (): void {
        $this->checks = governanceJson('required-checks.json')['required'] ?? [];
        $this->main = governanceJson('main-ruleset.json')['rulesets'][0] ?? [];
    });

    it('declares the seven contexts M28 evidenced, plus the gates M29-I and M33 added', function (): void {
        // Pinned as an exact set rather than a count, so that adding a context
        // is a deliberate edit to this list and removing one cannot pass
        // quietly. The eighth arrived in M29-I, once the workflow-integrity
        // check lost the `paths:` filter that would have made requiring it a
        // permanent block.
        //
        // The ninth is M33's `Mobile Certification`. It is the AGGREGATOR job
        // name in ga-flutter-certification.yml, deliberately — not either of
        // the two platform jobs it depends on. Requiring those would pin two
        // more byte-exact strings containing U+00B7 MIDDLE DOT into the
        // ruleset, and a later rename would stop them reporting, which is the
        // same permanent block described above. It is also not `Analyse · Test`,
        // the job inside ci-mobile.yml, which remains deliberately not required.
        expect(array_column($this->checks, 'context'))->toEqualCanonicalizing([
            'Lint · Analyse · Test',
            'Tests · SQLite',
            'Secret scanning',
            'Dependency audit',
            'Lint · Typecheck · Test · Build',
            'Lint spec · Generate types',
            'Build · Boot · Migrate · Healthcheck',
            'CI · Workflow Integrity',
            'Mobile Certification',
        ]);
    });

    it('requires the mobile aggregator and not its platform jobs', function (): void {
        // The distinction M33 turns on, asserted by exact comparison. Four
        // similar strings live here and only one belongs in the ruleset.
        $contexts = array_column($this->checks, 'context');

        expect($contexts)->toContain('Mobile Certification')
            ->not->toContain('Android · doctor · analyze · test · build apk')
            ->not->toContain('iOS · analyze · test · build (no codesign)')
            ->not->toContain('Analyse · Test')
            ->not->toContain('GA Flutter Certification');
    });

    it('keeps the ruleset and the documented list in agreement', function (): void {
        $inRuleset = array_column(
            ruleNamed($this->main, 'required_status_checks')['parameters']['required_status_checks'] ?? [],
            'context',
        );

        expect($inRuleset)->toEqualCanonicalizing(array_column($this->checks, 'context'));
    });

    it('names a job that actually exists for every context', function (): void {
        // Compared literally. Five contexts contain U+00B7 MIDDLE DOT, and a
        // context off by one character never reports at all.
        expect($this->checks)->not->toBeEmpty();

        foreach ($this->checks as $check) {
            $workflow = repoRoot().'/'.$check['workflow'];
            expect(file_exists($workflow))->toBeTrue("missing workflow {$check['workflow']}");

            $body = (string) file_get_contents($workflow);
            $matched = preg_match('/^\s*name:\s*'.preg_quote($check['context'], '/').'\s*$/m', $body) === 1;

            expect($matched)->toBeTrue("no job named '{$check['context']}' in {$check['workflow']}");
        }
    });

    it('refuses a path filter on any required workflow\'s pull_request trigger', function (): void {
        // Re-adding `paths:` here would leave every unrelated pull request
        // waiting on a check that never runs — unmergeable, with no error.
        $workflows = array_unique(array_column($this->checks, 'workflow'));
        expect($workflows)->not->toBeEmpty();

        foreach ($workflows as $relative) {
            $body = (string) file_get_contents(repoRoot().'/'.$relative);

            expect(preg_match('/^  pull_request:/m', $body))->toBe(1, "{$relative} has no pull_request trigger");

            preg_match('/^  pull_request:\s*$\n((?:^(?:    .*|\s*)$\n)*)/m', $body, $m);
            $block = preg_replace('/^\s*#.*$/m', '', $m[1] ?? '') ?? '';

            expect(preg_match('/^\s+paths(-ignore)?:/m', $block))
                ->toBe(0, "{$relative} filters pull_request by path — its required check would hang");
        }
    });

    it('excludes the workflows that cannot or must not gate a pull request', function (): void {
        $all = array_merge(array_column($this->checks, 'context'), array_column($this->checks, 'workflow'));

        foreach ($all as $value) {
            expect($value)->not->toContain('GA Docker')
                ->and($value)->not->toContain('release.yml');
        }
    });
});

describe('CODEOWNERS', function (): void {
    beforeEach(function (): void {
        $this->path = repoRoot().'/.github/CODEOWNERS';
        expect(file_exists($this->path))->toBeTrue();
        $this->body = (string) file_get_contents($this->path);

        $this->activeRules = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $t) ?: [];
            $this->activeRules[] = ['line' => $i + 1, 'pattern' => array_shift($parts), 'owners' => $parts];
        }
    });

    it('names no owner it cannot resolve', function (): void {
        // The defect M29-A found, as a standing assertion: eight unresolvable
        // @eruofood/* handles in a file that read as configured.
        foreach ($this->activeRules as $rule) {
            expect($rule['owners'])->not->toBeEmpty("line {$rule['line']}: no owner");

            foreach ($rule['owners'] as $owner) {
                $valid = preg_match('/^@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\/[A-Za-z0-9._-]+)?$/', $owner) === 1
                    || filter_var($owner, FILTER_VALIDATE_EMAIL) !== false;

                expect($valid)->toBeTrue("line {$rule['line']}: '{$owner}' is not a resolvable owner handle");
            }
        }
    });

    it('routes to none of the teams that never existed', function (): void {
        // nzebrian/eruofood-ai is owned by a User. @eruofood/* teams cannot be
        // created at all while that is true, and all eight references failed to
        // resolve.
        //
        // Asserted over *active rules* rather than the whole file: the header
        // deliberately names those handles while explaining why they are gone,
        // and a test that forbade the explanation would push the next person
        // towards deleting the history rather than keeping it.
        foreach ($this->activeRules as $rule) {
            foreach ($rule['owners'] as $owner) {
                expect($owner)->not->toStartWith('@eruofood/', "line {$rule['line']} still routes to {$owner}");
            }
        }

        // And no rule may be uncommented into existence carrying one.
        expect(preg_match('/^\s*[^#\s]\S*\s+.*@eruofood\//m', $this->body))
            ->toBe(0, 'an active rule references a team that cannot exist');
    });

    it('never mixes placeholders with active rules', function (): void {
        // Half-migrated is the dangerous state: some paths routed, others
        // silently unowned, and no signal telling you which.
        $hasPlaceholders = preg_match('/<OWNER:[A-Z_]+>/', $this->body) === 1;

        if ($hasPlaceholders) {
            expect($this->activeRules)->toBe([], 'placeholders and active rules coexist');
        }
    });

    it('preserves every ownership domain, even while unassigned', function (string $domain): void {
        expect($this->body)->toContain($domain);
    })->with([
        'apps/api/',
        'apps/api/modules/Payments/',
        'apps/web/',
        'apps/mobile/',
        'packages/api-contracts/',
        'infra/',
        '.github/workflows/',
        '.github/governance/',
        'docs/PRODUCTION_DEPLOYMENT.md',
        '.github/CODEOWNERS',
    ]);

    it('explains what must happen before code-owner review is enabled', function (): void {
        expect($this->body)->toContain('write access')
            ->and($this->body)->toContain('codeowners/errors')
            ->and($this->body)->toContain('require_code_owner_review');
    });
});

describe('break-glass', function (): void {
    beforeEach(function (): void {
        $this->body = (string) file_get_contents(repoRoot().'/.github/governance/BREAK_GLASS.md');
    });

    it('documents every field an incident record must carry', function (string $field): void {
        expect(strtoupper($this->body))->toContain($field);
    })->with([
        'INCIDENT ID', 'REASON', 'RISK ASSESSMENT', 'AUTHORIZED BY',
        'TEMPORARY RULE CHANGE', 'START TIME', 'END TIME', 'ACTION PERFORMED',
        'VERIFICATION', 'RULE RESTORATION', 'POST-INCIDENT REVIEW',
    ]);

    it('forbids a standing bypass and prescribes disable-and-restore instead', function (): void {
        expect(strtolower($this->body))->toContain('no standing bypass')
            ->and($this->body)->toContain('enforcement=disabled')
            ->and($this->body)->toContain('enforcement=active');
    });

    it('holds the financial guard rails open during an incident', function (): void {
        // A window opened to fix a governance misconfiguration is not
        // authorisation to touch the financial system.
        expect($this->body)->toContain('settlement.execute')
            ->and($this->body)->toContain('settlement.accrual_posting')
            ->and(strtoupper($this->body))->toContain('UNKNOWN');
    });
});
