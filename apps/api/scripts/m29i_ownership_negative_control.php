<?php

declare(strict_types=1);

/**
 * M29-I — the ownership mode cannot be used to switch governance off.
 *
 * `ownership.json` decides which ruleset artifact applies and which human-review
 * controls are deferred. That makes it the single most attractive file to edit
 * if somebody wanted the gates to relax: declare SOLE_OWNER, and independent
 * review, code-owner review and finance four-eyes all stop being expected.
 *
 * So every way of abusing it is broken here in turn and the corresponding
 * finding is required by CODE — OWNERSHIP_MODE_UNKNOWN,
 * OWNERSHIP_PARTICIPANT_NOT_HUMAN and the rest — rather than by "it exited
 * non-zero".
 *
 * ## M37 — fixtures, and a mode that can no longer vanish
 *
 * This suite used to rewrite and delete the REAL `.github/governance/
 * ownership.json`, restoring it in a `finally` that PHP does not run on a fatal
 * error, an OOM or the SIGTERM a cancelled CI job receives. An interrupted run
 * left the repository declaring a mode nobody chose — or none at all.
 *
 * Everything now happens inside a unique `mktemp` fixture, and the suite
 * fingerprints the real governance tree before and after to prove it.
 *
 * M37 also made this file's job sharper elsewhere: the three SOLE_OWNER
 * deferrals in the validator are now SKIPPED rather than EXTERNAL, and that
 * reclassification is gated on `ownership.json` parsing into a usable
 * declaration. Controls 1 and 10 below are what stop that becoming an escape
 * hatch — a broken or absent declaration must not make checks disappear.
 *
 * Run: php scripts/m29i_ownership_negative_control.php
 */

require __DIR__.'/m29_fixture_lib.php';

$passed = 0;
$falseNegatives = 0;

$fingerprintBefore = m29_fingerprint();

echo "EruoFood — M29-I ownership mode negative controls\n";
echo str_repeat('=', 78), "\n";
echo "fixtures: unique mktemp copies; the real repository is read-only\n\n";

function m29i_ownership(string $fixture): string
{
    return $fixture.'/.github/governance/ownership.json';
}

function m29i_sole_ruleset(string $fixture): string
{
    return $fixture.'/.github/governance/main-ruleset.sole-owner.json';
}

/** Mutate the sole-owner ruleset's rules in place. */
function m29i_edit_sole(string $fixture, callable $edit): void
{
    $path = m29i_sole_ruleset($fixture);
    $doc = m29_read_json($path);

    foreach ($doc['rulesets'] as &$ruleset) {
        $edit($ruleset);
    }

    m29_write_json($path, $doc);
}

// -- 1. An unrecognised ownership mode is declared ----------------------------

m29_control(
    'an unrecognised ownership mode is declared',
    static function (string $f): void {
        $doc = m29_read_json(m29i_ownership($f));
        $doc['mode'] = 'BENEVOLENT_DICTATOR';
        m29_write_json(m29i_ownership($f), $doc);
    },
    'OWNERSHIP_MODE_UNKNOWN',
);

// -- 2. An AI assistant is declared as a human participant --------------------

m29_control(
    'an AI assistant is declared as a human participant',
    static function (string $f): void {
        $doc = m29_read_json(m29i_ownership($f));
        $doc['human_participants'] = ['nzebrian', 'claude'];
        m29_write_json(m29i_ownership($f), $doc);
    },
    'OWNERSHIP_PARTICIPANT_NOT_HUMAN',
);

// -- 3. MULTI_PERSON is declared with only one participant --------------------

m29_control(
    'MULTI_PERSON is declared with only one participant',
    static function (string $f): void {
        $doc = m29_read_json(m29i_ownership($f));
        $doc['mode'] = 'MULTI_PERSON';
        $doc['human_participants'] = ['nzebrian'];
        m29_write_json(m29i_ownership($f), $doc);
    },
    'OWNERSHIP_MODE_CONTRADICTS_PARTICIPANTS',
);

// -- 4. The sole-owner ruleset requires an approval it cannot get -------------

m29_control(
    'the sole-owner ruleset requires an approval it cannot get',
    static function (string $f): void {
        m29i_edit_sole($f, static function (array &$ruleset): void {
            foreach ($ruleset['rules'] as &$rule) {
                if ($rule['type'] === 'pull_request') {
                    $rule['parameters']['required_approving_review_count'] = 1;
                }
            }
        });
    },
    'required_approving_review_count',
);

// -- 5. Code-owner review is required while CODEOWNERS is inert ---------------

m29_control(
    'code-owner review is required while CODEOWNERS is inert',
    static function (string $f): void {
        m29i_edit_sole($f, static function (array &$ruleset): void {
            foreach ($ruleset['rules'] as &$rule) {
                if ($rule['type'] === 'pull_request') {
                    $rule['parameters']['require_code_owner_review'] = true;
                }
            }
        });
    },
    'require_code_owner_review',
);

// -- 6. A required status check is dropped from the sole-owner ruleset --------
//
// The automated gates must be identical in both modes. Relaxing human review
// is the point of SOLE_OWNER; relaxing the machines is not.

m29_control(
    'a required status check is dropped from the sole-owner ruleset',
    static function (string $f): void {
        m29i_edit_sole($f, static function (array &$ruleset): void {
            foreach ($ruleset['rules'] as &$rule) {
                if ($rule['type'] === 'required_status_checks') {
                    array_pop($rule['parameters']['required_status_checks']);
                }
            }
        });
    },
    'required status checks differ',
);

// -- 7. The sole-owner ruleset gains a bypass actor ---------------------------

m29_control(
    'the sole-owner ruleset gains a bypass actor',
    static function (string $f): void {
        m29i_edit_sole($f, static function (array &$ruleset): void {
            $ruleset['bypass_actors'] = [
                ['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
            ];
        });
    },
    'sole-owner ruleset carries bypass actors',
);

// -- 8. The declared owner does not match the artifacts -----------------------

m29_control(
    'the declared owner does not match the repository the artifacts describe',
    static function (string $f): void {
        $doc = m29_read_json(m29i_ownership($f));
        $doc['repository_owner'] = 'somebody-else';
        m29_write_json(m29i_ownership($f), $doc);
    },
    'the declared owner matches',
);

// -- 9. An AI assistant reaches a code-owner slot -----------------------------

m29_identity_control(
    'an AI assistant is named as a code owner',
    static function (string $f): void {
        $roles = ['MAINTAINERS', 'API', 'FINANCE', 'WEB', 'MOBILE', 'PLATFORM', 'GOVERNANCE'];
        $codeowners = [];

        foreach ($roles as $role) {
            $codeowners[$role] = ['handles' => [$role === 'FINANCE' ? '@chatgpt' : '@alpha-reviewer']];
        }

        m29_write_json($f.'/.github/governance/identities.json', [
            'repository' => 'nzebrian/eruofood-ai',
            'codeowners' => $codeowners,
            'release_actors' => [['actor_id' => 12345, 'actor_type' => 'Integration', 'bypass_mode' => 'always']],
        ]);
    },
    'IDENTITY_NOT_HUMAN',
);

// -- 10. The declaration goes missing entirely --------------------------------

m29_control(
    'the ownership declaration is deleted',
    static fn (string $f) => unlink(m29i_ownership($f)),
    'OWNERSHIP_DECLARATION_MISSING',
);

// -- 11. A broken declaration cannot make checks disappear (M37) --------------
//
// The SKIPPED reclassification is gated on the declaration being usable. This
// proves the gate: with ownership.json unusable, the three SOLE_OWNER
// deferrals must come back as EXTERNAL rather than silently counting as
// deliberately-skipped.

$fixture11 = m29_make_fixture();

try {
    $doc = m29_read_json(m29i_ownership($fixture11));
    $doc['mode'] = 'BENEVOLENT_DICTATOR';
    m29_write_json(m29i_ownership($fixture11), $doc);
    m29_assert_contained($fixture11);

    $result = m29_run_validator($fixture11);
    $summary = $result['summary'];

    $noneSkipped = ($summary['skipped'] ?? -1) === 0;
    $degraded = str_contains($result['output'], 'skip condition not verified');

    if ($noneSkipped && $degraded) {
        $passed++;
        echo "  ✔ an unusable ownership declaration cannot masquerade as SKIPPED\n";
        echo "      skipped=0; the deferrals degraded back to EXTERNAL / ADMIN REQUIRED\n";
    } else {
        $falseNegatives++;
        printf(
            "  ✘ an unusable ownership declaration STILL produced SKIPPED (skipped=%s, degraded=%s)\n",
            (string) ($summary['skipped'] ?? '?'),
            $degraded ? 'yes' : 'NO',
        );
    }
} finally {
    m29_rmtree($fixture11);
}

// -- The controls on the controls ---------------------------------------------

echo "\n";
m29_positive_control();
m29_completeness_control('.github/workflows', 'workflow missing');

// -- Real-tree integrity ------------------------------------------------------

$fingerprintAfter = m29_fingerprint();

if ($fingerprintBefore === $fingerprintAfter) {
    $passed++;
    echo "  ✔ integrity · the real governance tree is byte-identical after the suite\n";
} else {
    $falseNegatives++;
    echo "  ✘ integrity · THE REAL GOVERNANCE TREE CHANGED — a mutation escaped its fixture\n";
}

// -- Result -------------------------------------------------------------------

$total = $passed + $falseNegatives;

echo "\n", str_repeat('=', 78), "\n";
printf('RESULT: %d/%d controls confirmed', $passed, $total);
echo $falseNegatives === 0 ? " — the mode cannot be used to switch governance off.\n" : sprintf(", %d FALSE NEGATIVE(S).\n", $falseNegatives);

exit($falseNegatives === 0 ? 0 : 1);
