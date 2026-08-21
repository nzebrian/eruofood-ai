<?php

declare(strict_types=1);

/**
 * M29-A — do the governance checks actually bite?
 *
 * Every check in `verify_repository_governance.php` and
 * `RepositoryGovernanceTest` currently passes. That is exactly the state in
 * which a vacuous check is invisible: it passes because the thing it guards is
 * fine, and it would also pass if it were guarding nothing at all.
 *
 * So this breaks each protection in turn, requires the corresponding check to
 * fail, and puts it back. A control that does not produce a failure is reported
 * as a FALSE NEGATIVE — the check is not earning its place.
 *
 * The pattern is M27's negative-control audit applied to governance rather than
 * to money. It exists because M28 found a five-provider adapter sweep that had
 * been testing one adapter five times, green the whole time.
 *
 * Every mutation is written to a temporary copy and the original is restored in
 * a `finally`, so an interrupted run cannot leave a damaged artifact behind.
 *
 * Run: php scripts/m29a_governance_negative_control.php
 */

$repoRoot = dirname(__DIR__, 3);
$validator = __DIR__.'/verify_repository_governance.php';

$passed = 0;
$falseNegatives = 0;

/**
 * Apply a mutation, run a command, require it to fail, then restore.
 *
 * @param list<string> $files absolute paths whose contents must be restored
 * @param callable():void $mutate
 */
function control(string $name, array $files, callable $mutate, string $command, string $expectSubstring = ''): void
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

        $bit = $exit !== 0 && ($expectSubstring === '' || str_contains($text, $expectSubstring));

        if ($bit) {
            $passed++;
            printf("  ✔ %s\n", $name);

            foreach (array_slice(array_filter($output, static fn (string $l): bool => str_contains($l, 'FAIL')), 0, 2) as $line) {
                printf("      %s\n", trim($line));
            }
        } else {
            $falseNegatives++;
            printf("  ✘ %s — FALSE NEGATIVE (exit=%d)\n", $name, $exit);
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

$governance = $repoRoot.'/.github/governance';
$codeowners = $repoRoot.'/.github/CODEOWNERS';
$ciApi = $repoRoot.'/.github/workflows/ci-api.yml';

$validatorCmd = 'php '.escapeshellarg($validator);
$testCmd = 'cd '.escapeshellarg($repoRoot.'/apps/api')
    .' && vendor/bin/pest modules/Shared/tests/Feature/RepositoryGovernanceTest.php';

echo "EruoFood — M29-A governance negative controls\n";
echo str_repeat('=', 78), "\n\n";

// -- 1. A governance artifact goes missing ------------------------------------

control(
    'a governance artifact is deleted',
    [$governance.'/BREAK_GLASS.md'],
    static fn () => unlink($governance.'/BREAK_GLASS.md'),
    $validatorCmd,
    'BREAK_GLASS.md',
);

// -- 2. A required check is removed from the ruleset --------------------------

control(
    'a required check is removed from the main ruleset',
    [$governance.'/main-ruleset.json'],
    static function () use ($governance): void {
        $doc = json_decode((string) file_get_contents($governance.'/main-ruleset.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($doc['rulesets'][0]['rules'] as $i => $rule) {
            if ($rule['type'] === 'required_status_checks') {
                array_pop($doc['rulesets'][0]['rules'][$i]['parameters']['required_status_checks']);
            }
        }

        file_put_contents($governance.'/main-ruleset.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    },
    $validatorCmd,
    'contexts agree',
);

// -- 3. The force-push protection is dropped ----------------------------------

control(
    'the non-fast-forward rule is dropped from main',
    [$governance.'/main-ruleset.json'],
    static function () use ($governance): void {
        $doc = json_decode((string) file_get_contents($governance.'/main-ruleset.json'), true, 512, JSON_THROW_ON_ERROR);

        $doc['rulesets'][0]['rules'] = array_values(array_filter(
            $doc['rulesets'][0]['rules'],
            static fn (array $r): bool => $r['type'] !== 'non_fast_forward',
        ));

        file_put_contents($governance.'/main-ruleset.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    },
    $validatorCmd,
    'force-pushed',
);

// -- 4. A standing bypass actor is introduced ---------------------------------

control(
    'a standing bypass actor is added to main',
    [$governance.'/main-ruleset.json'],
    static function () use ($governance): void {
        $doc = json_decode((string) file_get_contents($governance.'/main-ruleset.json'), true, 512, JSON_THROW_ON_ERROR);
        $doc['rulesets'][0]['bypass_actors'] = [
            ['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
        ];

        file_put_contents($governance.'/main-ruleset.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    },
    $validatorCmd,
    'bypass',
);

// -- 5. The tag-immutability ruleset gains a bypass ---------------------------

control(
    'the tag-immutability ruleset gains a bypass actor',
    [$governance.'/production-tags-ruleset.json'],
    static function () use ($governance): void {
        $doc = json_decode((string) file_get_contents($governance.'/production-tags-ruleset.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($doc['rulesets'] as $i => $rs) {
            foreach ($rs['rules'] as $rule) {
                if ($rule['type'] === 'deletion') {
                    $doc['rulesets'][$i]['bypass_actors'] = [
                        ['actor_id' => 1, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
                    ];
                }
            }
        }

        file_put_contents($governance.'/production-tags-ruleset.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    },
    $validatorCmd,
    'bypass',
);

// -- 6. A path filter comes back on a required workflow -----------------------

control(
    'a pull_request path filter is re-added to ci-api.yml',
    [$ciApi],
    static function () use ($ciApi): void {
        // The regression this milestone most needs to catch: it breaks nothing
        // visibly and makes every unrelated pull request permanently unmergeable.
        $body = (string) file_get_contents($ciApi);
        $body = preg_replace(
            '/^  pull_request:\s*$/m',
            "  pull_request:\n    paths: [\"apps/api/**\"]",
            $body,
            1,
        );
        file_put_contents($ciApi, (string) $body);
    },
    $validatorCmd,
    'path-filtered',
);

// -- 7. An unresolvable owner is reintroduced ---------------------------------

control(
    'an unresolvable owner is reintroduced to CODEOWNERS',
    [$codeowners],
    static function () use ($codeowners): void {
        // The original defect: a team handle that cannot exist under a personal
        // account, in a file that reads as configured.
        file_put_contents($codeowners, (string) file_get_contents($codeowners)."\n/apps/api/ @eruofood/backend\n");
    },
    $testCmd,
    'FAIL',
);

// -- 8. A placeholder owner is left active ------------------------------------

control(
    'a placeholder token is left in an active rule',
    [$codeowners],
    static function () use ($codeowners): void {
        file_put_contents($codeowners, (string) file_get_contents($codeowners)."\n/apps/api/ <OWNER:API>\n");
    },
    $validatorCmd,
    'resolvable owner handle',
);

// -- 9. A known-bad workflow is added to the required list --------------------

control(
    'GA Docker Certification is added to the required checks',
    [$governance.'/required-checks.json', $governance.'/main-ruleset.json'],
    static function () use ($governance): void {
        $doc = json_decode((string) file_get_contents($governance.'/required-checks.json'), true, 512, JSON_THROW_ON_ERROR);
        $doc['required'][] = [
            'context' => 'GA Docker Certification',
            'workflow' => '.github/workflows/ga-docker-certification.yml',
        ];

        file_put_contents($governance.'/required-checks.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    },
    $validatorCmd,
    'wrongly required',
);

// -- 10. Break-glass loses a required field -----------------------------------

control(
    'BREAK_GLASS.md loses a required incident field',
    [$governance.'/BREAK_GLASS.md'],
    static function () use ($governance): void {
        $body = (string) file_get_contents($governance.'/BREAK_GLASS.md');
        $body = str_ireplace('RISK ASSESSMENT', 'REMOVED FIELD', $body);
        file_put_contents($governance.'/BREAK_GLASS.md', $body);
    },
    $validatorCmd,
    'RISK ASSESSMENT',
);

// -- Result -------------------------------------------------------------------

$total = $passed + $falseNegatives;

echo "\n", str_repeat('=', 78), "\n";
printf("RESULT: %d/%d controls confirmed", $passed, $total);
echo $falseNegatives === 0 ? " — every check bites.\n" : sprintf(", %d FALSE NEGATIVE(S).\n", $falseNegatives);

exit($falseNegatives === 0 ? 0 : 1);
