<?php

declare(strict_types=1);

/**
 * M29-E — the gate that watches the workflows.
 *
 * ## What went wrong, and why nothing caught it
 *
 * `.github/workflows/release.yml` was invalid YAML from 2026-08-04 until M29-D.
 * GitHub could not parse it, so it could not load the workflow, so it could not
 * evaluate its triggers — which is why a *branch* push produced a run at all
 * when the only trigger was `push: tags:`. Thirty-odd runs, on main and on every
 * dependabot branch, each finishing in the same second with zero jobs.
 *
 * The reason it survived that long is the shape of the symptom. In the Actions
 * tab a workflow that cannot be parsed is indistinguishable from a gate that ran
 * and failed. The file called itself a MANDATORY production gate throughout, and
 * `production-tags-ruleset.json` repeated the claim.
 *
 * ## What these tests are for
 *
 * The real validation is `actionlint`, run in CI by
 * `.github/workflows/workflow-integrity.yml`. These tests cover the two things
 * actionlint cannot:
 *
 * 1. That the gate is *configured* the way it claims — right triggers, minimum
 *    permissions, all workflows rather than only the changed ones, a pinned and
 *    checksum-verified linter, and a negative control wired in. A validator can
 *    be present and still be inert.
 * 2. A standing check for the exact historical pattern, in PHP, so the
 *    regression is caught even where actionlint is not installed — which is the
 *    situation on every developer machine in this repository today.
 *
 * The second one deliberately duplicates part of actionlint's job. That is the
 * point: this defect cost the project its release gate for two weeks, and one
 * independent check that needs no toolchain is cheap insurance.
 */
function m29eRepoRoot(): string
{
    return dirname(base_path(), 2);
}

function m29eWorkflow(): string
{
    $path = m29eRepoRoot().'/.github/workflows/workflow-integrity.yml';

    expect(file_exists($path))->toBeTrue('workflow-integrity.yml is missing');

    return (string) file_get_contents($path);
}

function m29eNegativeControlPath(): string
{
    return m29eRepoRoot().'/.github/scripts/workflow_integrity_negative_control.sh';
}

/**
 * Workflow bodies with comment lines removed.
 *
 * Necessary rather than fastidious: `release.yml` documents the defect by
 * quoting it, so a naive scan for the broken pattern finds the warning against
 * it and fails on the very file that was fixed.
 *
 * @return array<string, string> relative path => body without comments
 */
function m29eWorkflowBodies(): array
{
    $bodies = [];

    foreach (glob(m29eRepoRoot().'/.github/workflows/*.yml') ?: [] as $path) {
        $stripped = preg_replace('/^[ \t]*#.*$/m', '', (string) file_get_contents($path));
        $bodies[basename($path)] = (string) $stripped;
    }

    return $bodies;
}

// -----------------------------------------------------------------------------

describe('the workflow integrity gate', function (): void {
    it('exists and is named so it can be required later', function (): void {
        // The MIDDLE DOT (U+00B7) matches the other workflows. GitHub matches
        // required status checks on this string byte for byte, so a plain
        // hyphen here would quietly detach the rule that requires it.
        expect(m29eWorkflow())->toContain('name: CI · Workflow Integrity');
        expect(m29eWorkflow())->toContain('name: Validate · actionlint');
    });

    it('runs on pull requests and on pushes to main that touch a workflow', function (): void {
        $body = m29eWorkflow();

        expect($body)->toMatch('/pull_request:\s*\n\s*paths: \[".github\/workflows\/\*\*"\]/');
        expect($body)->toMatch('/push:\s*\n\s*branches: \[main\]\s*\n\s*paths: \[".github\/workflows\/\*\*"\]/');
    });

    it('asks for nothing beyond read access', function (): void {
        // A job that reads files and runs a linter needs no write scope, and a
        // workflow-validation job holding one would be a strange thing to hand
        // a future contributor's pull request.
        expect(m29eWorkflow())->toMatch('/^permissions:\n  contents: read\n/m');

        foreach (['packages:', 'id-token:', 'pull-requests: write', 'contents: write'] as $forbidden) {
            expect(str_contains(m29eWorkflow(), $forbidden))
                ->toBeFalse("workflow-integrity.yml requests {$forbidden}");
        }
    });

    it('validates every workflow, not only the changed ones', function (): void {
        // A change to one workflow can invalidate another, and the defect that
        // prompted all this sat untouched in a file no pull request had opened
        // for months. Linting only the diff would have missed it every time.
        expect(m29eWorkflow())->toContain('actionlint -color .github/workflows/*.yml');
    });

    it('pins the linter to an exact version and verifies it before use', function (): void {
        $body = m29eWorkflow();

        expect($body)->toMatch('/ACTIONLINT_VERSION: "\d+\.\d+\.\d+"/');
        expect($body)->toMatch('/ACTIONLINT_SHA256: "[0-9a-f]{64}"/');

        // Verified before extraction, so an archive that fails the check is
        // never unpacked and nothing from it can run.
        $checkAt = strpos($body, 'sha256sum --check');
        $extractAt = strpos($body, 'tar -xzf');

        expect($checkAt)->not->toBeFalse('no checksum verification');
        expect($extractAt)->not->toBeFalse('no extraction step');
        expect($checkAt)->toBeLessThan((int) $extractAt, 'the archive is extracted before it is verified');
    });

    it('runs the negative control as part of the gate', function (): void {
        expect(m29eWorkflow())->toContain('.github/scripts/workflow_integrity_negative_control.sh');
    });

    it('passes its own gate', function (): void {
        // Circular only in appearance: a workflow-validation workflow that is
        // itself invalid cannot run, and therefore cannot report that it is
        // invalid. That is precisely how release.yml failed silently.
        $bodies = m29eWorkflowBodies();

        expect($bodies)->toHaveKey('workflow-integrity.yml');
        expect(count($bodies))->toBeGreaterThan(1);
    });
});

describe('the negative control', function (): void {
    it('exists and is executable', function (): void {
        // Committed without the executable bit, the workflow step fails with a
        // permission error that reads like infrastructure noise rather than a
        // missing gate.
        expect(file_exists(m29eNegativeControlPath()))->toBeTrue('negative control script is missing');
        expect(is_executable(m29eNegativeControlPath()))->toBeTrue('negative control script is not executable');
    });

    it('feeds the gate the historical defect verbatim', function (): void {
        $body = (string) file_get_contents(m29eNegativeControlPath());

        expect($body)->toContain('env: { GITHUB_TOKEN: ${{ github.token }} }');
        expect($body)->toContain('[syntax-check]');
    });

    it('also covers defects a YAML parser alone would accept', function (): void {
        // Valid YAML, invalid Actions. Without these the gate would only be a
        // syntax check, and half the ways to break a workflow would pass it.
        $body = (string) file_get_contents(m29eNegativeControlPath());

        expect($body)->toContain('[job-needs]');
        expect($body)->toContain('[expression]');
    });

    it('carries a positive control', function (): void {
        // The control on the controls. Without one, the suite cannot tell a
        // working validator from one that rejects everything handed to it —
        // and M28 found a five-adapter sweep that had been testing one adapter
        // five times while green throughout.
        $body = (string) file_get_contents(m29eNegativeControlPath());

        expect($body)->toContain('accept ');
        expect($body)->toContain('WRONGLY REJECTED');
    });

    it('writes its fixtures outside .github/workflows and cleans them up', function (): void {
        $body = (string) file_get_contents(m29eNegativeControlPath());

        expect($body)->toContain('mktemp -d');
        expect($body)->toMatch('/trap .*rm -rf.*EXIT/');

        // It also proves it did no harm, rather than asserting it.
        expect($body)->toContain('sha256 verified before and after');
    });
});

describe('the historical defect itself', function (): void {
    it('appears in no active line of any workflow', function (): void {
        // The standing guard, in PHP, so it holds on a developer machine with
        // no actionlint installed. Inside a YAML flow mapping the `{` of `${{`
        // opens a nested mapping and the document stops being valid YAML.
        $offenders = [];

        foreach (m29eWorkflowBodies() as $name => $body) {
            foreach (explode("\n", $body) as $i => $line) {
                if (preg_match('/\{[^}]*:\s*\$\{\{/', $line) === 1) {
                    $offenders[] = sprintf('%s line %d: %s', $name, $i + 1, trim($line));
                }
            }
        }

        expect($offenders)->toBe([], "an expression sits inside a YAML flow mapping:\n".implode("\n", $offenders));
    });

    it('is scanned across every workflow, so the sweep is not vacuous', function (): void {
        // Guards the guard. If the glob ever stopped matching, the check above
        // would pass by finding nothing to look at.
        $bodies = m29eWorkflowBodies();

        expect(count($bodies))->toBeGreaterThanOrEqual(12);
        expect($bodies)->toHaveKey('release.yml');
    });

    it('is still detected when it is present', function (): void {
        // The scan proved against a known-bad input rather than only against a
        // clean repository, where it would pass whether or not it worked.
        $line = '        env: { GITHUB_TOKEN: ${{ github.token }} }';

        expect(preg_match('/\{[^}]*:\s*\$\{\{/', $line))->toBe(1);

        // And the repaired form is not flagged.
        expect(preg_match('/\{[^}]*:\s*\$\{\{/', '          GITHUB_TOKEN: "${{ github.token }}"'))->toBe(0);
    });
});
