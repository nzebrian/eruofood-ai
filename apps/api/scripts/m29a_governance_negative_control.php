<?php

declare(strict_types=1);

/**
 * M29-A — do the governance checks actually bite?
 *
 * Every check in `verify_repository_governance.php` currently passes. That is
 * exactly the state in which a vacuous check is invisible: it passes because
 * the thing it guards is fine, and it would also pass if it were guarding
 * nothing at all. So this breaks each protection in turn and requires the
 * corresponding check to fail. A control that does not produce a failure is
 * reported as a FALSE NEGATIVE.
 *
 * The pattern is M27's negative-control audit applied to governance rather than
 * to money. It exists because M28 found a five-provider adapter sweep that had
 * been testing one adapter five times, green the whole time.
 *
 * ## M37 — every mutation now happens in a fixture
 *
 * This suite used to mutate the REAL repository — deleting BREAK_GLASS.md,
 * rewriting main-ruleset.json, appending to .github/CODEOWNERS — and restore it
 * in a `finally`. PHP does not run `finally` on a fatal error, on OOM, or on the
 * SIGTERM a cancelled CI job receives, so an interrupted run left governance
 * artifacts corrupted on disk. Mutations now happen only inside a unique
 * `mktemp` fixture that the validator is pointed at with `--repo-root=`, and the
 * suite fingerprints the real governance tree before and after to prove it was
 * never touched.
 *
 * Run: php scripts/m29a_governance_negative_control.php
 */

require __DIR__.'/m29_fixture_lib.php';

$passed = 0;
$falseNegatives = 0;

$fingerprintBefore = m29_fingerprint();

echo "EruoFood — M29-A governance negative controls\n";
echo str_repeat('=', 78), "\n";
echo "fixtures: unique mktemp copies; the real repository is read-only\n\n";

/** Rewrite a JSON artifact inside the fixture. */
function m29a_edit_json(string $path, callable $edit): void
{
    $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $edit($doc);
    file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// -- 1. A governance artifact goes missing ------------------------------------

m29_control(
    'a governance artifact is deleted',
    static fn (string $f) => unlink($f.'/.github/governance/BREAK_GLASS.md'),
    'BREAK_GLASS.md',
);

// -- 2. A required check is removed from the main ruleset ---------------------

m29_control(
    'a required check is removed from the main ruleset',
    static function (string $f): void {
        m29a_edit_json($f.'/.github/governance/main-ruleset.json', static function (array &$doc): void {
            foreach ($doc['rulesets'] as &$ruleset) {
                foreach ($ruleset['rules'] as &$rule) {
                    if ($rule['type'] === 'required_status_checks') {
                        array_pop($rule['parameters']['required_status_checks']);
                    }
                }
            }
        });
    },
    'contexts agree',
);

// -- 3. The non-fast-forward rule is dropped ----------------------------------

m29_control(
    'the non-fast-forward rule is dropped from main',
    static function (string $f): void {
        m29a_edit_json($f.'/.github/governance/main-ruleset.json', static function (array &$doc): void {
            foreach ($doc['rulesets'] as &$ruleset) {
                $ruleset['rules'] = array_values(array_filter(
                    $ruleset['rules'],
                    static fn (array $r): bool => $r['type'] !== 'non_fast_forward',
                ));
            }
        });
    },
    'force-pushed',
);

// -- 4. A standing bypass actor is added --------------------------------------

m29_control(
    'a standing bypass actor is added to main',
    static function (string $f): void {
        m29a_edit_json($f.'/.github/governance/main-ruleset.json', static function (array &$doc): void {
            foreach ($doc['rulesets'] as &$ruleset) {
                $ruleset['bypass_actors'] = [
                    ['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
                ];
            }
        });
    },
    'bypass',
);

// -- 5. The tag-immutability ruleset gains a bypass actor ---------------------

m29_control(
    'the tag-immutability ruleset gains a bypass actor',
    static function (string $f): void {
        m29a_edit_json($f.'/.github/governance/production-tags-ruleset.json', static function (array &$doc): void {
            if (! isset($doc['rulesets'])) {
                $doc['bypass_actors'] = [
                    ['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
                ];

                return;
            }

            foreach ($doc['rulesets'] as &$ruleset) {
                $ruleset['bypass_actors'] = [
                    ['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
                ];
            }
        });
    },
    'bypass',
);

// -- 6. A pull_request path filter is re-added --------------------------------
//
// The trap this whole milestone turns on. GitHub treats a required check that
// never reports as pending, not satisfied, so a path filter here silently
// converts a required check into a permanent block on every unrelated PR.

m29_control(
    'a pull_request path filter is re-added to ci-api.yml',
    static function (string $f): void {
        $path = $f.'/.github/workflows/ci-api.yml';
        $body = (string) file_get_contents($path);
        $body = preg_replace(
            '/^  pull_request:\s*$/m',
            "  pull_request:\n    paths:\n      - \"apps/api/**\"",
            $body,
            1,
        );
        file_put_contents($path, (string) $body);
    },
    'path-filtered',
);

// -- 7. An unresolvable owner is reintroduced to CODEOWNERS -------------------
//
// M37 note — this control used to run the Pest suite
// (modules/Shared/tests/Feature/RepositoryGovernanceTest.php) rather than the
// validator, which is why the old suite pulled in the full dev-dependency tree.
// That test derives its root from `dirname(base_path(), 2)`: it reads the REAL
// repository and cannot be pointed at a fixture without adding a root override
// to a test whose whole job is guarding governance.
//
// So the assertion moved to the validator, which catches the same mutation with
// a specific finding of its own ('unresolved ownership is marked, not faked').
// What is NOT claimed: this no longer proves that the *Pest test* also bites.
// That test still runs on every pull request inside two required contexts —
// `Tests · SQLite` and `Lint · Analyse · Test` — so the invariant remains
// enforced; only the second-implementation cross-check moved out of this suite.
// Recorded rather than quietly dropped.

m29_control(
    'an unresolvable owner is reintroduced to CODEOWNERS',
    static function (string $f): void {
        $path = $f.'/.github/CODEOWNERS';
        file_put_contents($path, (string) file_get_contents($path)."\n/apps/api/ @eruofood/backend\n");
    },
    'unresolved ownership is marked, not faked',
);

// -- 8. A placeholder owner is left active ------------------------------------

m29_control(
    'a placeholder token is left in an active rule',
    static function (string $f): void {
        $path = $f.'/.github/CODEOWNERS';
        file_put_contents($path, (string) file_get_contents($path)."\n/apps/api/ <OWNER:API>\n");
    },
    'resolvable owner handle',
);

// -- 9. A known-failing workflow is wrongly required --------------------------

m29_control(
    'a known-failing workflow is added to the required list',
    static function (string $f): void {
        m29a_edit_json($f.'/.github/governance/required-checks.json', static function (array &$doc): void {
            $doc['required'][] = [
                'context' => 'GA Docker Certification',
                'workflow' => '.github/workflows/ga-docker-certification.yml',
            ];
        });
    },
    'wrongly required',
);

// -- 10. Break-glass loses a required field -----------------------------------

m29_control(
    'BREAK_GLASS.md loses a required incident field',
    static function (string $f): void {
        $path = $f.'/.github/governance/BREAK_GLASS.md';
        file_put_contents($path, str_ireplace('RISK ASSESSMENT', 'REMOVED FIELD', (string) file_get_contents($path)));
    },
    'RISK ASSESSMENT',
);

// -- 11. A symlink cannot escape the fixture (M37) ----------------------------
//
// The whole fixture design rests on mutations being unable to reach the real
// repository. A symlink planted inside a fixture and pointing at a real
// governance file would defeat that silently, so the containment assertion is
// itself tested: plant one, and require m29_assert_contained() to object.

$symlinkFixture = m29_make_fixture();

try {
    $escape = $symlinkFixture.'/.github/governance/escape.json';
    symlink(m29_repo_root().'/.github/governance/ownership.json', $escape);

    $caught = false;

    try {
        m29_assert_contained($symlinkFixture);
    } catch (RuntimeException $e) {
        $caught = str_contains($e->getMessage(), 'symlink');
    }

    if ($caught) {
        $passed++;
        echo "  ✔ symlink containment · a link pointing out of the fixture is refused\n";
    } else {
        $falseNegatives++;
        echo "  ✘ symlink containment · a link out of the fixture was NOT refused\n";
    }
} finally {
    m29_rmtree($symlinkFixture);
}

// -- The controls on the controls ---------------------------------------------

echo "\n";
m29_positive_control();
m29_completeness_control('.github/CODEOWNERS', 'CODEOWNERS');

// -- Real-tree integrity ------------------------------------------------------

$fingerprintAfter = m29_fingerprint();

if ($fingerprintBefore === $fingerprintAfter) {
    $passed++;
    echo "  ✔ integrity · the real governance tree is byte-identical after the suite\n";
} else {
    $falseNegatives++;
    echo "  ✘ integrity · THE REAL GOVERNANCE TREE CHANGED — a mutation escaped its fixture\n";
    printf("      before=%s\n      after =%s\n", $fingerprintBefore, $fingerprintAfter);
}

// -- Result -------------------------------------------------------------------

$total = $passed + $falseNegatives;

echo "\n", str_repeat('=', 78), "\n";
printf('RESULT: %d/%d controls confirmed', $passed, $total);
echo $falseNegatives === 0 ? " — every check bites.\n" : sprintf(", %d FALSE NEGATIVE(S).\n", $falseNegatives);

exit($falseNegatives === 0 ? 0 : 1);
