<?php

declare(strict_types=1);

/**
 * M29-B — does the identity resolver actually bite?
 *
 * `verify_governance_identities.php` decides whether the `<OWNER:...>` tokens
 * in CODEOWNERS can be filled in by real handles, and whether the result is
 * ready for activation. Every finding it can raise is asserted here by CODE —
 * IDENTITY_ROLE_MISSING, CODEOWNERS_PLACEHOLDER_ACTIVE and the rest — rather
 * than by "it exited non-zero", because the resolver exits non-zero for several
 * reasons and a control that cannot say which is not evidence.
 *
 * ## M37 — fixtures, and the end of a circular safety argument
 *
 * This suite used to write `.github/governance/identities.json` into the REAL
 * repository — a path that, while it exists, IS an active identity
 * configuration — and restore it in a `finally` that PHP does not run on a
 * fatal error or a cancelled CI job.
 *
 * Worse, control 10 invoked the resolver with
 * `--render-codeowners=<the live .github/CODEOWNERS>` and relied on the
 * resolver's own refusal to stop the write. The safety of the control depended
 * on the correctness of the code it was testing; a regression in that guard
 * would have overwritten the live CODEOWNERS as its first symptom. That control
 * now renders to a fixture path, so no refusal is load-bearing for safety and
 * the refusal itself is still asserted.
 *
 * The resolver already accepts --identities, --codeowners and --tags, so every
 * path it reads can be pointed at the fixture explicitly. No root parameter is
 * needed here.
 *
 * Run: php scripts/m29b_identity_negative_control.php
 */

require __DIR__.'/m29_fixture_lib.php';

$passed = 0;
$falseNegatives = 0;

$fingerprintBefore = m29_fingerprint();

echo "EruoFood — M29-B identity activation negative controls\n";
echo str_repeat('=', 78), "\n";
echo "fixtures: unique mktemp copies; the real repository is read-only\n\n";

/** A configuration with nothing wrong with it, as a base for mutation. */
function baseIdentities(): array
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

function m29b_identities_path(string $fixture): string
{
    return $fixture.'/.github/governance/identities.json';
}

// -- 1. A required CODEOWNERS role is missing ---------------------------------

m29_identity_control(
    'a required CODEOWNERS role is missing',
    static function (string $f): void {
        $doc = baseIdentities();
        unset($doc['codeowners']['FINANCE']);
        m29_write_json(m29b_identities_path($f), $doc);
    },
    'IDENTITY_ROLE_MISSING',
);

// -- 2. A role is present but names nobody ------------------------------------

m29_identity_control(
    'a role is present but names nobody',
    static function (string $f): void {
        $doc = baseIdentities();
        $doc['codeowners']['FINANCE']['handles'] = [];
        m29_write_json(m29b_identities_path($f), $doc);
    },
    'IDENTITY_EMPTY',
);

// -- 3. An unresolved token is left on an active CODEOWNERS rule --------------

m29_identity_control(
    'an unresolved <OWNER:...> token is left on an active CODEOWNERS rule',
    static function (string $f): void {
        m29_write_json(m29b_identities_path($f), baseIdentities());
        $codeowners = $f.'/.github/CODEOWNERS';
        file_put_contents($codeowners, (string) file_get_contents($codeowners)."\n/apps/api/modules/Payments/ <OWNER:FINANCE>\n");
    },
    'CODEOWNERS_PLACEHOLDER_ACTIVE',
);

// -- 4. The example file is copied in and activated unchanged -----------------

m29_identity_control(
    'the example file is copied in and activated unchanged',
    static function (string $f): void {
        copy(
            $f.'/.github/governance/identities.example.json',
            m29b_identities_path($f),
        );
    },
    'IDENTITY_EXAMPLE_USED_AS_ACTIVE',
);

// -- 5. A handle uses a syntax GitHub rejects ---------------------------------

m29_identity_control(
    'a handle uses a syntax GitHub rejects',
    static function (string $f): void {
        $doc = baseIdentities();
        $doc['codeowners']['API']['handles'] = ['not-an-at-handle'];
        m29_write_json(m29b_identities_path($f), $doc);
    },
    'IDENTITY_SYNTAX_INVALID',
);

// -- 6. No release actor is configured ----------------------------------------

m29_identity_control(
    'no release actor is configured',
    static function (string $f): void {
        $doc = baseIdentities();
        $doc['release_actors'] = [];
        m29_write_json(m29b_identities_path($f), $doc);
    },
    'RELEASE_ACTOR_MISSING',
);

// -- 7. The release actor is made exempt from tag immutability ----------------

m29_identity_control(
    'the release actor is made exempt from tag immutability',
    static function (string $f): void {
        m29_write_json(m29b_identities_path($f), baseIdentities());

        $tagPath = $f.'/.github/governance/production-tags-ruleset.json';
        $doc = m29_read_json($tagPath);
        $actor = ['actor_id' => 12345, 'actor_type' => 'Integration', 'bypass_mode' => 'always'];

        if (isset($doc['rulesets']) && is_array($doc['rulesets'])) {
            foreach ($doc['rulesets'] as &$ruleset) {
                if (str_contains(strtolower((string) ($ruleset['name'] ?? '')), 'immutable')) {
                    $ruleset['bypass_actors'] = [$actor];
                }
            }
        } else {
            $doc['bypass_actors'] = [$actor];
        }

        m29_write_json($tagPath, $doc);
    },
    'RELEASE_ACTOR_IN_IMMUTABILITY_BYPASS',
);

// -- 8. An unknown role is added instead of a known one being filled ----------

m29_identity_control(
    'an unknown role is added instead of a known one being filled',
    static function (string $f): void {
        $doc = baseIdentities();
        $doc['codeowners']['DEVOPS'] = ['handles' => ['@theta-reviewer']];
        m29_write_json(m29b_identities_path($f), $doc);
    },
    'IDENTITY_ROLE_UNKNOWN',
);

// -- 9. A flawless identity file still proves nothing about GitHub ------------
//
// Inverted: the validator MUST succeed here. The defect guarded against is the
// opposite of a missing failure — a local file being promoted into a claim
// about a remote system. So the requirement is that the repository validator
// exits 0, still reports the GitHub facts as EXTERNAL, and never prints a PASS
// for enforcement or for identities resolving.

$fixture9 = m29_make_fixture();

try {
    m29_write_json(m29b_identities_path($fixture9), baseIdentities());
    m29_assert_contained($fixture9);

    $result = m29_run_validator($fixture9);
    $forbidden = [
        'PASS the main ruleset is actually active on GitHub',
        'PASS branch protection is effective',
        'PASS CODEOWNER identities resolve',
    ];

    $clean = true;
    foreach ($forbidden as $needle) {
        if (str_contains($result['output'], $needle)) {
            $clean = false;
        }
    }

    $reportsExternal = str_contains(
        $result['output'],
        'EXTERNAL / ADMIN REQUIRED  the main ruleset is actually active on GitHub',
    );

    if ($result['exit'] === 0 && $clean && $reportsExternal) {
        $passed++;
        echo "  ✔ a flawless identity file still proves nothing about GitHub\n";
        echo "      GitHub facts remain EXTERNAL; no PASS was printed for enforcement\n";
    } else {
        $falseNegatives++;
        printf(
            "  ✘ a flawless identity file still proves nothing about GitHub — FALSE NEGATIVE (exit=%d, external=%s, no-forbidden=%s)\n",
            $result['exit'],
            $reportsExternal ? 'yes' : 'NO',
            $clean ? 'yes' : 'NO',
        );
    }
} finally {
    m29_rmtree($fixture9);
}

// -- 10. The resolver is told to render over an ACTIVE CODEOWNERS -------------
//
// M37: the render target is the FIXTURE's CODEOWNERS, never the repository's.
// The refusal is still asserted — but it is no longer what keeps the real file
// safe, because the real file is not reachable from here at all.

$fixture10 = m29_make_fixture();
$liveCodeownersBefore = hash_file('sha256', m29_repo_root().'/.github/CODEOWNERS');

try {
    m29_write_json(m29b_identities_path($fixture10), baseIdentities());
    m29_assert_contained($fixture10);

    $result = m29_run_identities($fixture10, [
        '--render-codeowners='.escapeshellarg($fixture10.'/.github/CODEOWNERS'),
    ]);

    if (str_contains($result['output'], 'REFUSED  will not write to the active .github/CODEOWNERS')) {
        $passed++;
        echo "  ✔ the resolver refuses to render over the CODEOWNERS it was given as active\n";
        echo "      (target was the fixture's copy — the real file was never a candidate)\n";
    } else {
        $falseNegatives++;
        echo "  ✘ the resolver did NOT refuse to render over an active CODEOWNERS\n";
    }
} finally {
    m29_rmtree($fixture10);
}

$liveCodeownersAfter = hash_file('sha256', m29_repo_root().'/.github/CODEOWNERS');

if ($liveCodeownersBefore === $liveCodeownersAfter) {
    $passed++;
    echo "  ✔ the real .github/CODEOWNERS is byte-identical (sha256 before and after)\n";
} else {
    $falseNegatives++;
    echo "  ✘ THE REAL .github/CODEOWNERS CHANGED — a render escaped its fixture\n";
}

// -- The controls on the controls ---------------------------------------------

echo "\n";
m29_positive_control();
m29_completeness_control('.github/governance', 'governance artifact');

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
echo $falseNegatives === 0 ? " — every check bites.\n" : sprintf(", %d FALSE NEGATIVE(S).\n", $falseNegatives);

exit($falseNegatives === 0 ? 0 : 1);
