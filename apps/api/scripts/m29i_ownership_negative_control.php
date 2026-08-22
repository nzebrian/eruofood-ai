<?php

declare(strict_types=1);

/**
 * M29-I — does the ownership mode actually bite, or does it just relax things?
 *
 * ## What is being guarded
 *
 * SOLE_OWNER exists because this repository has one human and the M29-A ruleset
 * assumes several. That is a legitimate reason to change two review parameters.
 * It is also exactly the shape of change that, left unchecked, quietly becomes
 * "governance mode: off".
 *
 * So the controls below try to abuse the mode the way somebody in a hurry
 * would: drop a status check while claiming automated controls are active, open
 * a bypass, declare MULTI_PERSON without a second person, point the ruleset at
 * the wrong approval count, and — the one that matters most — name an AI
 * assistant as the second reviewer, which would satisfy every other check in
 * this repository while providing none of the review it appears to provide.
 *
 * Each control mutates one artifact, requires the validator to fail, and
 * restores it in a `finally`. A control that produces no failure is reported as
 * a FALSE NEGATIVE.
 *
 * Run: php scripts/m29i_ownership_negative_control.php
 */

$repoRoot = dirname(__DIR__, 3);

$governance = $repoRoot.'/.github/governance';
$ownership = $governance.'/ownership.json';
$soleRuleset = $governance.'/main-ruleset.sole-owner.json';
$identities = $governance.'/identities.json';

$repoCmd = 'php '.escapeshellarg(__DIR__.'/verify_repository_governance.php');
$identityCmd = 'php '.escapeshellarg(__DIR__.'/verify_governance_identities.php');

$passed = 0;
$falseNegatives = 0;

function writeJson(string $path, array $doc): void
{
    file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function readJsonFile(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Apply a mutation, run a command, require it to fail, then restore.
 *
 * @param list<string> $files
 * @param callable():void $mutate
 */
function control(string $name, array $files, callable $mutate, string $command, string $expect): void
{
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

        if ($exit !== 0 && str_contains($text, $expect)) {
            $passed++;
            printf("  ✔ %s\n", $name);

            $evidence = array_filter($output, static fn (string $l): bool => str_contains($l, $expect));

            foreach (array_slice($evidence, 0, 1) as $line) {
                printf("      %s\n", trim(preg_replace('/\s+/', ' ', $line) ?? ''));
            }
        } else {
            $falseNegatives++;
            printf("  ✘ %s — FALSE NEGATIVE (exit=%d, expected \"%s\")\n", $name, $exit, $expect);
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

echo "EruoFood — M29-I ownership mode negative controls\n";
echo str_repeat('=', 78), "\n\n";

// -- 1. A mode nobody implemented ---------------------------------------------

control(
    'an unrecognised ownership mode is declared',
    [$ownership],
    static function () use ($ownership): void {
        $doc = readJsonFile($ownership);
        $doc['mode'] = 'RELAXED';
        writeJson($ownership, $doc);
    },
    $repoCmd,
    'OWNERSHIP_MODE_UNKNOWN',
);

// -- 2. An AI assistant as a human participant --------------------------------

control(
    'an AI assistant is declared as a human participant',
    [$ownership],
    static function () use ($ownership): void {
        // The failure with the most convincing green report. A synthetic second
        // reviewer satisfies mode consistency, participant count, handle syntax
        // and the not-the-owner rule, and reviews nothing.
        $doc = readJsonFile($ownership);
        $doc['human_participants'][] = 'claude';
        writeJson($ownership, $doc);
    },
    $repoCmd,
    'OWNERSHIP_PARTICIPANT_NOT_HUMAN',
);

// -- 3. MULTI_PERSON declared without a second person -------------------------

control(
    'MULTI_PERSON is declared with only one participant',
    [$ownership],
    static function () use ($ownership): void {
        $doc = readJsonFile($ownership);
        $doc['mode'] = 'MULTI_PERSON';
        writeJson($ownership, $doc);
    },
    $repoCmd,
    'OWNERSHIP_MODE_CONTRADICTS_PARTICIPANTS',
);

// -- 4. The ruleset stops matching the declared mode --------------------------

control(
    'the sole-owner ruleset requires an approval it cannot get',
    [$soleRuleset],
    static function () use ($soleRuleset): void {
        // The mistake that blocks every merge on a one-human repository, and
        // then gets "fixed" by disabling the whole ruleset.
        $doc = readJsonFile($soleRuleset);

        foreach ($doc['rulesets'][0]['rules'] as $i => $rule) {
            if ($rule['type'] === 'pull_request') {
                $doc['rulesets'][0]['rules'][$i]['parameters']['required_approving_review_count'] = 1;
            }
        }

        writeJson($soleRuleset, $doc);
    },
    $repoCmd,
    'required_approving_review_count',
);

// -- 5. Code-owner review required against an inert CODEOWNERS ----------------

control(
    'code-owner review is required while CODEOWNERS is inert',
    [$soleRuleset],
    static function () use ($soleRuleset): void {
        // The M29-A defect, reachable through the mode rather than through the
        // file: requiring review from owners nothing resolves.
        $doc = readJsonFile($soleRuleset);

        foreach ($doc['rulesets'][0]['rules'] as $i => $rule) {
            if ($rule['type'] === 'pull_request') {
                $doc['rulesets'][0]['rules'][$i]['parameters']['require_code_owner_review'] = true;
            }
        }

        writeJson($soleRuleset, $doc);
    },
    $repoCmd,
    'require_code_owner_review',
);

// -- 6. An automated gate is dropped under cover of the mode ------------------

control(
    'a required status check is dropped from the sole-owner ruleset',
    [$soleRuleset],
    static function () use ($soleRuleset): void {
        // "Automated controls: ACTIVE" has to be true. This is the mutation that
        // would make the banner a lie while every other check still passed.
        $doc = readJsonFile($soleRuleset);

        foreach ($doc['rulesets'][0]['rules'] as $i => $rule) {
            if ($rule['type'] === 'required_status_checks') {
                array_pop($doc['rulesets'][0]['rules'][$i]['parameters']['required_status_checks']);
            }
        }

        writeJson($soleRuleset, $doc);
    },
    $repoCmd,
    'required status checks differ',
);

// -- 7. A bypass actor appears on the sole-owner ruleset ----------------------

control(
    'the sole-owner ruleset gains a bypass actor',
    [$soleRuleset],
    static function () use ($soleRuleset): void {
        $doc = readJsonFile($soleRuleset);
        $doc['rulesets'][0]['bypass_actors'] = [
            ['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
        ];
        writeJson($soleRuleset, $doc);
    },
    $repoCmd,
    // The exact phrase from the sole-owner check, not the bare word "bypass":
    // main-ruleset.json has its own bypass assertion, and matching loosely let
    // this control pass on the wrong file's output.
    'sole-owner ruleset carries bypass actors',
);

// -- 8. The declaration disagrees with the other artifacts --------------------

control(
    'the declared owner does not match the repository the artifacts describe',
    [$ownership],
    static function () use ($ownership): void {
        // If these drift, every owner-comparison rule is being applied against
        // the wrong account and silently means nothing.
        $doc = readJsonFile($ownership);
        $doc['repository_owner'] = 'someone-else';
        writeJson($ownership, $doc);
    },
    $repoCmd,
    'the declared owner matches',
);

// -- 9. An AI assistant reaches a code-owner slot -----------------------------

control(
    'an AI assistant is named as a code owner',
    [$identities],
    static function () use ($identities): void {
        $roles = ['MAINTAINERS', 'API', 'FINANCE', 'WEB', 'MOBILE', 'PLATFORM', 'GOVERNANCE'];
        $codeowners = [];

        foreach ($roles as $role) {
            $codeowners[$role] = ['handles' => [$role === 'FINANCE' ? '@chatgpt' : '@alpha-reviewer']];
        }

        writeJson($identities, [
            'repository' => 'nzebrian/eruofood-ai',
            'codeowners' => $codeowners,
            'release_actors' => [['actor_id' => 12345, 'actor_type' => 'Integration', 'bypass_mode' => 'always']],
        ]);
    },
    $identityCmd,
    'IDENTITY_NOT_HUMAN',
);

// -- 10. The declaration goes missing entirely --------------------------------

control(
    'the ownership declaration is deleted',
    [$ownership],
    static fn () => unlink($ownership),
    $repoCmd,
    'OWNERSHIP_DECLARATION_MISSING',
);

// -- Result -------------------------------------------------------------------

$total = $passed + $falseNegatives;

echo "\n", str_repeat('=', 78), "\n";
printf('RESULT: %d/%d controls confirmed', $passed, $total);
echo $falseNegatives === 0 ? " — the mode cannot be used to switch governance off.\n" : sprintf(", %d FALSE NEGATIVE(S).\n", $falseNegatives);

exit($falseNegatives === 0 ? 0 : 1);
