<?php

declare(strict_types=1);

/**
 * M29-B — do the activation checks actually bite?
 *
 * The M29-A harness asked this of the governance artifacts. This asks it of the
 * identity layer, and it asks it of the *scripts* rather than of the policy
 * class: `GovernanceIdentityTest` already proves the rules are right, which is a
 * different claim from proving that the command an administrator actually runs
 * exits non-zero when they are broken. A correct rule behind a script that
 * swallows its result is not a gate.
 *
 * Every control here writes a real `.github/governance/identities.json`, breaks
 * one thing, requires the corresponding command to fail, and removes it again in
 * a `finally`. Two are shaped differently and say so where they are defined:
 *
 * - **9** requires the validator to *succeed* while still refusing to claim
 *   GitHub enforcement. Inverted, because the failure it guards against is a
 *   false PASS rather than a missing FAIL.
 * - **10** requires the resolver to refuse, and then checks that the live
 *   CODEOWNERS is byte-identical. A refusal that had already written the file
 *   would report correctly and still have destroyed the thing it was protecting.
 *
 * The harness exists because M28 found a five-adapter test sweep that had been
 * exercising one adapter five times, green throughout. A check that passes
 * because the thing it guards is fine looks exactly like a check that guards
 * nothing.
 *
 * Run: php scripts/m29b_identity_negative_control.php
 */

$repoRoot = dirname(__DIR__, 3);

$governance = $repoRoot.'/.github/governance';
$identities = $governance.'/identities.json';
$codeowners = $repoRoot.'/.github/CODEOWNERS';
$tagRuleset = $governance.'/production-tags-ruleset.json';

$identityCmd = 'php '.escapeshellarg(__DIR__.'/verify_governance_identities.php');
$repoCmd = 'php '.escapeshellarg(__DIR__.'/verify_repository_governance.php');

$passed = 0;
$falseNegatives = 0;

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

function writeJson(string $path, array $doc): void
{
    file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Apply a mutation, run a command, require the expected outcome, then restore.
 *
 * @param list<string> $files absolute paths whose contents must be restored
 * @param callable():void $mutate
 * @param list<string> $forbid substrings that must NOT appear in the output
 */
function control(
    string $name,
    array $files,
    callable $mutate,
    string $command,
    string $expectSubstring = '',
    bool $expectFailure = true,
    array $forbid = [],
): void {
    global $passed, $falseNegatives;

    $backups = [];
    foreach ($files as $file) {
        $backups[$file] = is_file($file) ? (string) file_get_contents($file) : null;
    }

    try {
        $mutate();

        $output = [];
        $exit = 0;
        exec($command.' 2>&1', $output, $exit);
        $text = implode("\n", $output);

        $exitOk = $expectFailure ? $exit !== 0 : $exit === 0;
        $substringOk = $expectSubstring === '' || str_contains($text, $expectSubstring);
        $forbidOk = true;

        foreach ($forbid as $needle) {
            if (str_contains($text, $needle)) {
                $forbidOk = false;
            }
        }

        if ($exitOk && $substringOk && $forbidOk) {
            $passed++;
            printf("  ✔ %s\n", $name);

            // Show the finding that actually fired. Section headings mention
            // EXTERNAL too, so those are only worth printing when nothing
            // sharper matched — as in control 9, where an EXTERNAL line *is*
            // the evidence.
            $sharp = array_filter(
                $output,
                static fn (string $l): bool => str_contains($l, 'ERROR ') || str_contains($l, 'REFUSED')
                    || str_starts_with(trim($l), 'FAIL '),
            );

            $evidence = $sharp !== [] ? $sharp : array_filter(
                $output,
                static fn (string $l): bool => str_starts_with(trim($l), 'EXTERNAL / ADMIN REQUIRED'),
            );

            foreach (array_slice($evidence, 0, 2) as $line) {
                printf("      %s\n", trim($line));
            }
        } else {
            $falseNegatives++;
            printf(
                "  ✘ %s — FALSE NEGATIVE (exit=%d, expected %s%s%s)\n",
                $name,
                $exit,
                $expectFailure ? 'failure' : 'success',
                $substringOk ? '' : "; missing \"{$expectSubstring}\"",
                $forbidOk ? '' : '; forbidden text present',
            );
        }
    } finally {
        foreach ($backups as $file => $content) {
            if ($content === null) {
                @unlink($file);
            } else {
                file_put_contents($file, $content);
            }
        }
    }
}

echo "EruoFood — M29-B identity activation negative controls\n";
echo str_repeat('=', 78), "\n\n";

// -- 1. A required role is missing --------------------------------------------

control(
    'a required CODEOWNERS role is missing',
    [$identities],
    static function () use ($identities): void {
        $doc = baseIdentities();
        unset($doc['codeowners']['FINANCE']);
        writeJson($identities, $doc);
    },
    $identityCmd,
    'IDENTITY_ROLE_MISSING',
);

// -- 2. An identity is empty ---------------------------------------------------

control(
    'a role is present but names nobody',
    [$identities],
    static function () use ($identities): void {
        $doc = baseIdentities();
        $doc['codeowners']['PLATFORM']['handles'] = [];
        writeJson($identities, $doc);
    },
    $identityCmd,
    'IDENTITY_EMPTY',
);

// -- 3. An unresolved placeholder reaches an active CODEOWNERS rule -----------

control(
    'an unresolved <OWNER:...> token is left on an active CODEOWNERS rule',
    [$identities, $codeowners],
    static function () use ($identities, $codeowners): void {
        // The regression this milestone exists to catch. The commented form is
        // the correct M29-A hand-over state; uncommenting without substituting
        // is the M29-A defect restored by the act of fixing it.
        writeJson($identities, baseIdentities());
        file_put_contents($codeowners, (string) file_get_contents($codeowners)."\n/apps/api/modules/Payments/ <OWNER:FINANCE>\n");
    },
    $identityCmd,
    'CODEOWNERS_PLACEHOLDER_ACTIVE',
);

// -- 4. The shipped example is used as the active configuration ---------------

control(
    'the example file is copied in and activated unchanged',
    [$identities],
    static function () use ($identities, $governance): void {
        copy($governance.'/identities.example.json', $identities);
    },
    $identityCmd,
    'IDENTITY_EXAMPLE_USED_AS_ACTIVE',
);

// -- 5. A handle GitHub will not accept ---------------------------------------

control(
    'a handle uses a syntax GitHub rejects',
    [$identities],
    static function () use ($identities): void {
        $doc = baseIdentities();
        $doc['codeowners']['API']['handles'] = ['@double--hyphen'];
        writeJson($identities, $doc);
    },
    $identityCmd,
    'IDENTITY_SYNTAX_INVALID',
);

// -- 6. No release actor -------------------------------------------------------

control(
    'no release actor is configured',
    [$identities],
    static function () use ($identities): void {
        $doc = baseIdentities();
        unset($doc['release_actors']);
        writeJson($identities, $doc);
    },
    $identityCmd,
    'RELEASE_ACTOR_MISSING',
);

// -- 7. The release actor gains tag-immutability bypass -----------------------

control(
    'the release actor is made exempt from tag immutability',
    [$identities, $tagRuleset],
    static function () use ($identities, $tagRuleset): void {
        // Two files, each of which reads as correct alone. The creation ruleset
        // names a release actor, which is its job; the immutability ruleset
        // names the same actor, which makes the release tag deletable by the
        // account that created it — and release.yml promotes a production
        // container image from exactly that tag.
        writeJson($identities, baseIdentities());

        $doc = json_decode((string) file_get_contents($tagRuleset), true, 512, JSON_THROW_ON_ERROR);

        foreach ($doc['rulesets'] as $i => $rs) {
            $types = array_column($rs['rules'], 'type');

            if (array_intersect(['deletion', 'non_fast_forward', 'update'], $types) !== []) {
                $doc['rulesets'][$i]['bypass_actors'] = [
                    ['actor_id' => 12345, 'actor_type' => 'Integration', 'bypass_mode' => 'always'],
                ];
            }
        }

        writeJson($tagRuleset, $doc);
    },
    $identityCmd,
    'RELEASE_ACTOR_IN_IMMUTABILITY_BYPASS',
);

// -- 8. An unknown role --------------------------------------------------------

control(
    'an unknown role is added instead of a known one being filled',
    [$identities],
    static function () use ($identities): void {
        // A typo, not sabotage: FINANCE stays unfilled while the file looks
        // longer and therefore more complete than the correct version.
        $doc = baseIdentities();
        $doc['codeowners']['FINANC'] = ['handles' => ['@typo-reviewer']];
        writeJson($identities, $doc);
    },
    $identityCmd,
    'IDENTITY_ROLE_UNKNOWN',
);

// -- 9. A perfect local configuration is not evidence of enforcement ----------

control(
    'a flawless identity file still proves nothing about GitHub',
    [$identities],
    static function () use ($identities): void {
        writeJson($identities, baseIdentities());
    },
    $repoCmd,
    // Inverted: the validator MUST succeed here. The defect being guarded
    // against is the opposite of a missing failure — it is a local file being
    // promoted into a claim about a remote system. So the requirement is that
    // it exits 0, still reports the GitHub facts as EXTERNAL, and never prints
    // a PASS for enforcement or for identities resolving.
    'EXTERNAL / ADMIN REQUIRED  the main ruleset is actually active on GitHub',
    expectFailure: false,
    forbid: [
        'PASS the main ruleset is actually active on GitHub',
        'PASS branch protection is effective',
        'PASS CODEOWNER identities resolve',
    ],
);

// -- 10. The resolver is pointed at the live CODEOWNERS -----------------------

$codeownersBefore = hash_file('sha256', $codeowners);

control(
    'the resolver is told to render over the active CODEOWNERS',
    [$identities],
    static function () use ($identities): void {
        writeJson($identities, baseIdentities());
    },
    $identityCmd
        .' --identities='.escapeshellarg($identities)
        .' --render-codeowners='.escapeshellarg($codeowners),
    'REFUSED  will not write to the active .github/CODEOWNERS',
);

$codeownersAfter = hash_file('sha256', $codeowners);

if ($codeownersBefore !== $codeownersAfter) {
    // A refusal that had already written the file would have reported correctly
    // and still destroyed the thing it was protecting. The message is not the
    // evidence; the unchanged file is.
    $falseNegatives++;
    echo "  ✘ the refusal did not protect the file — .github/CODEOWNERS changed\n";
} else {
    echo "      .github/CODEOWNERS unchanged (sha256 verified before and after)\n";
}

// -- Result -------------------------------------------------------------------

$total = $passed + $falseNegatives;

echo "\n", str_repeat('=', 78), "\n";
printf('RESULT: %d/%d controls confirmed', $passed, $total);
echo $falseNegatives === 0 ? " — every check bites.\n" : sprintf(", %d FALSE NEGATIVE(S).\n", $falseNegatives);

exit($falseNegatives === 0 ? 0 : 1);
