<?php

declare(strict_types=1);

/**
 * M29-A — is repository governance actually configured, or does it just look it?
 *
 * ## The distinction this script exists to hold
 *
 * Before M29-A, `.github/CODEOWNERS` had named six teams for months. The file
 * was present, well-formatted and plausible, and every single owner in it was
 * unresolvable — `GET /codeowners/errors` returned eight errors. Nothing in the
 * repository could have told you that, because the repository was not the
 * authority. GitHub was.
 *
 * So this validator reports in two categories and never blurs them:
 *
 *   PASS                     — proved from files in this repository.
 *   EXTERNAL / ADMIN REQUIRED — depends on GitHub state or on identities nobody
 *                               has supplied. Deferred, never assumed.
 *
 * **A JSON artifact describing a ruleset is not evidence that the ruleset
 * exists.** The artifacts under `.github/governance/` are prepared, not applied;
 * an EXTERNAL item is never upgraded to PASS because a file describing it is on
 * disk. That inversion is exactly the mistake the CODEOWNERS file made.
 *
 * ## Feeding it real data
 *
 *   gh api /repos/{owner}/{repo}/rulesets > rulesets.json
 *   gh api /repos/{owner}/{repo}/codeowners/errors > codeowners-errors.json
 *   php scripts/verify_repository_governance.php \
 *       --rulesets=rulesets.json --codeowners-errors=codeowners-errors.json
 *
 * With those, the matching external checks are evaluated for real rather than
 * deferred. Everything still unanswered stays EXTERNAL — supplying one file
 * does not resolve the others.
 *
 * Exit 0 when every repository-side check passes. A zero exit says nothing
 * about whether GitHub is enforcing anything.
 *
 * M29-B added section 7: the identity configuration that fills in the
 * `<OWNER:...>` tokens, and the activation readiness that follows from it. The
 * two-category rule is unchanged there — a resolved identity file is still not
 * evidence that the account exists or can push.
 */

require __DIR__.'/../vendor/autoload.php';

use EruoFood\Shared\Domain\Governance\ActivationState;
use EruoFood\Shared\Domain\Governance\GovernanceRole;
use EruoFood\Shared\Domain\Governance\IdentityFinding;
use EruoFood\Shared\Domain\Governance\IdentityPolicy;
use EruoFood\Shared\Domain\Governance\OwnershipDeclaration;

// Exit codes (M37). A single boolean exit could not say "I could not check
// this", so a run with twelve unverified items looked exactly like a run that
// verified everything.
const EXIT_OK = 0;             // verified, and passing
const EXIT_FAIL = 1;           // a governance invariant is violated
const EXIT_UNVERIFIED = 2;     // verification incomplete (strict mode only)
const EXIT_ERROR = 3;          // the validator could not run, or was misinvoked

// The machine-readable summary's schema. Bumped to 2 by M37 Phase 4B, which
// ADDED `failures[]` and `schema_notes`; every schema-1 field keeps its name,
// its type and its meaning, so an existing consumer is unaffected.
const SUMMARY_SCHEMA = 2;

/**
 * Stable identifiers for the checks that can be evaluated against live GitHub
 * evidence.
 *
 * The known-gap ratchet compares these, never console text. A check with no
 * identifier still reports normally and still fails the run — it simply cannot
 * be matched against a recorded gap, so the ratchet treats it as an unknown
 * failure and goes red. That is deliberate: the safe direction for an
 * unidentified failure is "somebody must look at this", not "assume it is
 * expected".
 *
 * These strings are a published contract. Renaming one is a governance change:
 * `.github/governance/known-gaps.json` references them by name, and a rename
 * that is not mirrored there makes the ratchet fail on an unknown identifier —
 * loudly, which is the intended failure mode.
 */
const CHECK_MAIN_RULESET_ACTIVE = 'github.main_ruleset_active';
const CHECK_TAG_RULESETS_ACTIVE = 'github.tag_rulesets_active';
const CHECK_NO_BYPASS_ACTORS = 'github.no_bypass_actors';
const CHECK_REQUIRED_CHECKS_ENFORCED = 'github.required_checks_enforced';
const CHECK_BRANCH_PROTECTION_EFFECTIVE = 'github.branch_protection_effective';
const CHECK_CODEOWNERS_ERRORS_ZERO = 'github.codeowners_errors_zero';
const CHECK_IDENTITY_ACCOUNTS_EXIST = 'github.identity_accounts_exist';
const CHECK_IDENTITY_WRITE_ACCESS = 'github.identity_accounts_write_access';
const CHECK_RELEASE_ACTOR_ID_VALID = 'github.release_actor_id_valid';
const CHECK_POLICY_INDEPENDENT_REVIEW = 'policy.independent_human_review';
const CHECK_POLICY_CODEOWNERS_ENFORCEMENT = 'policy.codeowners_enforcement';
const CHECK_POLICY_FINANCE_FOUR_EYES = 'policy.finance_four_eyes';

/**
 * Every identifier this validator can emit, in one place.
 *
 * The Phase 4B review found `governance_ratchet.php` carrying its own copy of
 * this list as string literals, with nothing asserting the two agreed. They did
 * agree, but only by hand. The list is now emitted in the `--json` summary as
 * `check_ids` and the ratchet reads it from there, so there is exactly one
 * definition and drift is not expressible.
 *
 * @var list<string>
 */
const PUBLISHED_CHECK_IDS = [
    CHECK_MAIN_RULESET_ACTIVE,
    CHECK_TAG_RULESETS_ACTIVE,
    CHECK_NO_BYPASS_ACTORS,
    CHECK_REQUIRED_CHECKS_ENFORCED,
    CHECK_BRANCH_PROTECTION_EFFECTIVE,
    CHECK_CODEOWNERS_ERRORS_ZERO,
    CHECK_IDENTITY_ACCOUNTS_EXIST,
    CHECK_IDENTITY_WRITE_ACCESS,
    CHECK_RELEASE_ACTOR_ID_VALID,
    CHECK_POLICY_INDEPENDENT_REVIEW,
    CHECK_POLICY_CODEOWNERS_ENFORCEMENT,
    CHECK_POLICY_FINANCE_FOUR_EYES,
];

/**
 * The identity-side external requirements arrive as prose from
 * IdentityAssessment::externalRequirements(). Mapping them here rather than
 * adding identifiers to the domain class keeps this phase out of `modules/`.
 *
 * The map is keyed by the exact string. If the domain wording changes and this
 * map is not updated, the lookup misses, the identifier is null, and an
 * unidentified failure makes the ratchet red — the fail-safe direction.
 */
const IDENTITY_REQUIREMENT_IDS = [
    'each configured account actually exists on GitHub' => CHECK_IDENTITY_ACCOUNTS_EXIST,
    'each configured account has write access (GitHub silently ignores a code owner who cannot push)' => CHECK_IDENTITY_WRITE_ACCESS,
    'CODEOWNER identities resolve (GET /codeowners/errors returns zero)' => CHECK_CODEOWNERS_ERRORS_ZERO,
    'the release actor id is one GitHub will accept in bypass_actors' => CHECK_RELEASE_ACTOR_ID_VALID,
];

// scripts -> api -> apps -> <repo root>. Three levels, not two: apps/api is
// the Laravel root, but governance artifacts live across the whole repository.
$repoRoot = dirname(__DIR__, 3);

// Live GitHub data, supplied as files rather than fetched. Read-only by
// construction: this script has no way to reach GitHub, so it has no way to
// change anything there. Whoever runs `gh api` decides what it sees.
$rulesetsFile = null;
$codeownerErrorsFile = null;
$mode = 'default';
$jsonPath = null;

/**
 * Bail out before any check runs, and say why.
 *
 * Invocation mistakes exit 3, never 0. Until M37 an unknown flag was silently
 * ignored: `--ruleset=live.json` (singular, a plausible typo of the real
 * `--rulesets=`) produced "37 passed, 0 failed, 12 external" and exit 0. CI
 * wired that way would have been green while verifying nothing about GitHub.
 */
function invocationError(string $message, ?string $jsonPath = null): never
{
    fprintf(STDERR, "ERROR  %s\n", $message);
    fprintf(STDERR, "       usage: verify_repository_governance.php [--mode=default|advisory|strict]\n");
    fprintf(STDERR, "              [--rulesets=<path>] [--codeowners-errors=<path>]\n");
    fprintf(STDERR, "              [--repo-root=<path>] [--json=<path>]\n");

    if ($jsonPath !== null) {
        @file_put_contents($jsonPath, json_encode([
            'schema' => SUMMARY_SCHEMA,
            'mode' => 'unknown',
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'external_unverified' => 0,
            'skipped' => 0,
            'error' => 1,
            'verification_complete' => false,
            'exit_code' => EXIT_ERROR,
            'exit_reason' => 'invocation_error',
            'unverified' => [],
            'failures' => [],
            'unverified_detail' => [],
            'check_ids' => PUBLISHED_CHECK_IDS,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    exit(EXIT_ERROR);
}

$argList = $GLOBALS['argv'] ?? [];
array_shift($argList);   // the script name

// Parsed in two passes so that --json is known before an unknown flag aborts,
// letting the machine-readable summary describe the invocation error too.
foreach ($argList as $arg) {
    if (str_starts_with((string) $arg, '--json=')) {
        $jsonPath = substr((string) $arg, strlen('--json='));
    }
}

foreach ($argList as $arg) {
    $arg = (string) $arg;

    if (str_starts_with($arg, '--rulesets=')) {
        $rulesetsFile = substr($arg, strlen('--rulesets='));

        if ($rulesetsFile === '') {
            invocationError('--rulesets= was given an empty path', $jsonPath);
        }

        continue;
    }

    if (str_starts_with($arg, '--codeowners-errors=')) {
        $codeownerErrorsFile = substr($arg, strlen('--codeowners-errors='));

        if ($codeownerErrorsFile === '') {
            invocationError('--codeowners-errors= was given an empty path', $jsonPath);
        }

        continue;
    }

    if (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, strlen('--mode='));

        if (! in_array($mode, ['default', 'advisory', 'strict'], true)) {
            invocationError("unknown mode '{$mode}' (expected default, advisory or strict)", $jsonPath);
        }

        continue;
    }

    if (str_starts_with($arg, '--repo-root=')) {
        $candidate = substr($arg, strlen('--repo-root='));

        if ($candidate === '') {
            invocationError('--repo-root= was given an empty path', $jsonPath);
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_dir($resolved)) {
            invocationError("--repo-root is not a readable directory: {$candidate}", $jsonPath);
        }

        // Every artifact this validator reads hangs off .github/, so a root
        // without one is a fixture that would silently "pass" by having
        // nothing to check. Refuse it rather than validate an empty tree.
        if (! is_dir($resolved.'/.github')) {
            invocationError("--repo-root has no .github directory: {$resolved}", $jsonPath);
        }

        $repoRoot = $resolved;

        continue;
    }

    if (str_starts_with($arg, '--json=')) {
        if ($jsonPath === '') {
            invocationError('--json= was given an empty path', $jsonPath);
        }

        continue;
    }

    invocationError("unrecognised argument: {$arg}", $jsonPath);
}

$passed = 0;
$failed = 0;
$external = 0;
$skipped = 0;

/** @var list<string> Descriptions of everything that could not be verified. */
$unverifiedItems = [];

/**
  * Every FAIL, structurally.
  *
   * @var list<array{id: string|null, check: string, detail: string}>
  */
$failures = [];

/**
 * Every EXTERNAL / ADMIN REQUIRED item, structurally.
 *
 * The ratchet needs this to tell "GitHub could not be reached" from "this is a
 * recorded governance gap". Both leave the run non-green, and treating them
 * alike would let an API outage masquerade as an expected state.
 *
 * @var list<array{id: string|null, check: string}>
 */
$unverifiedDetail = [];

function section(string $title): void
{
    echo "\n{$title}\n";
}

/** Record a FAIL for the machine-readable summary as well as the console. */
function recordFailure(?string $id, string $description, string $detail): void
{
    global $failures;

    $failures[] = ['id' => $id, 'check' => $description, 'detail' => $detail];
}

/** Record an EXTERNAL / ADMIN REQUIRED item for the machine-readable summary. */
function recordUnverified(?string $id, string $description): void
{
    global $unverifiedItems, $unverifiedDetail;

    $unverifiedItems[] = $description;
    $unverifiedDetail[] = ['id' => $id, 'check' => $description];
}

/** @param callable():array{bool, string} $check */
function verify(string $description, callable $check, ?string $id = null): void
{
    global $passed, $failed;

    try {
        [$ok, $detail] = $check();
    } catch (Throwable $e) {
        $ok = false;
        $detail = $e::class.': '.$e->getMessage();
    }

    if ($ok) {
        $passed++;
    } else {
        $failed++;
        recordFailure($id, $description, $detail);
    }

    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $description, $detail === '' ? '' : "  ({$detail})");
}

/**
 * Something only GitHub can answer.
 *
 * The callable returns `[bool|null, string]`. M37 Phase 4B added the `null`
 * arm, and it exists because of a specific defect: `GET /rulesets` does not
 * carry a `bypass_actors` key at all, and the bypass check read a missing key
 * as an empty one. It printed
 *
 *     PASS  no bypass actors on the main or tag-immutability rulesets
 *
 * on evidence that contained no information about bypass actors whatsoever —
 * the exact "green while proving nothing" failure this validator exists to
 * prevent, reached through the live-evidence path instead of the file path.
 *
 * `null` means "the evidence was supplied, and it does not answer this". That
 * is EXTERNAL / ADMIN REQUIRED, never PASS and never FAIL: nobody has done
 * anything wrong, and nothing has been proved.
 *
 * @param null|callable():array{bool|null, string} $check evaluated only when live data was supplied
 */
function externalCheck(string $description, ?callable $check = null, ?string $id = null): void
{
    global $external, $passed, $failed;

    if ($check === null) {
        $external++;
        recordUnverified($id, $description);
        printf("  EXTERNAL / ADMIN REQUIRED  %s\n", $description);

        return;
    }

    try {
        [$ok, $detail] = $check();
    } catch (Throwable $e) {
        $ok = false;
        $detail = $e::class.': '.$e->getMessage();
    }

    if ($ok === null) {
        $external++;
        recordUnverified($id, $description);
        printf(
            "  EXTERNAL / ADMIN REQUIRED  %s%s\n",
            $description,
            $detail === '' ? '' : "  (evidence does not answer this: {$detail})",
        );

        return;
    }

    if ($ok) {
        $passed++;
    } else {
        $failed++;
        recordFailure($id, $description, $detail);
    }

    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $description, $detail === '' ? '' : "  ({$detail})");
}

/**
 * A check that does not apply, because a policy condition says so.
 *
 * SKIPPED is not a softer EXTERNAL. EXTERNAL means "we could not find out";
 * SKIPPED means "policy says there is nothing here to find out", and the
 * difference only holds if the condition is *machine-verified* rather than
 * asserted by whoever wrote the call. So the caller must pass the evaluated
 * condition and the artifact it was read from, and a false condition degrades
 * to EXTERNAL rather than quietly counting as fine.
 *
 * Without that degradation this would be the perfect escape hatch: an absent
 * or malformed ownership.json would make three checks vanish into SKIPPED and
 * strict mode would report success having verified less than it thought.
 */
function skipCheck(string $description, bool $conditionHolds, string $because, ?string $id = null): void
{
    global $skipped, $external;

    if ($conditionHolds) {
        $skipped++;
        printf("  SKIPPED  %s  (%s)\n", $description, $because);

        return;
    }

    $external++;
    recordUnverified($id, $description);
    printf("  EXTERNAL / ADMIN REQUIRED  %s  (skip condition not verified: %s)\n", $description, $because);
}

/**
 * Live evidence, held to a shape before it is trusted.
 *
 * Returns the decoded payload, or null when the evidence cannot be trusted —
 * and increments $failed in that case, because supplying broken evidence is a
 * failure, not an absence of evidence. Until M37 a file containing literal
 * `null` decoded without throwing, failed the `is_array` test, and was
 * discarded in silence: the run reported the same twelve EXTERNAL items and
 * exit 0 as if nothing had been supplied at all.
 */
function readEvidence(string $flag, string $path, string $shape = 'any'): ?array
{
    global $failed;

    if (! is_file($path)) {
        printf("  FAIL could not read %s file  (no such file: %s)\n", $flag, $path);
        $failed++;

        return null;
    }

    $raw = @file_get_contents($path);

    if ($raw === false) {
        printf("  FAIL could not read %s file  (unreadable: %s)\n", $flag, $path);
        $failed++;

        return null;
    }

    if (trim($raw) === '') {
        printf("  FAIL %s file is empty  (%s)\n", $flag, $path);
        $failed++;

        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        printf("  FAIL could not read %s file  (%s)\n", $flag, $e->getMessage());
        $failed++;

        return null;
    }

    // Valid JSON is not the same as usable evidence. `null`, a bare string and
    // a number all decode cleanly and carry nothing.
    if (! is_array($decoded)) {
        printf(
            "  FAIL %s file is valid JSON but not a structured payload  (got %s)\n",
            $flag,
            get_debug_type($decoded),
        );
        $failed++;

        return null;
    }

    // A GitHub API error is valid JSON, and until M37 Phase 4B it sailed
    // through every check above. `{"message":"Resource not accessible by
    // integration","status":"403"}` is an array to PHP, so it was accepted,
    // iterated as a list of rulesets, found to contain none, and reported as
    //
    //     FAIL  the main ruleset is actually active on GitHub
    //           (no active ruleset targeting main; found: none)
    //
    // A transient 403 was therefore indistinguishable from somebody having
    // switched branch protection off. Refusing the envelope by name is the
    // narrow fix; the shape check below is the general one.
    if (isApiErrorEnvelope($decoded)) {
        printf(
            "  FAIL %s file is a GitHub API error response, not evidence  (message: %s)\n",
            $flag,
            (string) ($decoded['message'] ?? 'unknown'),
        );
        $failed++;

        return null;
    }

    if ($shape === 'list' && ! array_is_list($decoded)) {
        printf(
            "  FAIL %s file must be a JSON array of objects  (got a JSON object with keys: %s)\n",
            $flag,
            implode(', ', array_slice(array_map('strval', array_keys($decoded)), 0, 5)) ?: 'none',
        );
        $failed++;

        return null;
    }

    if ($shape === 'object' && array_is_list($decoded) && $decoded !== []) {
        printf("  FAIL %s file must be a JSON object  (got a JSON array)\n", $flag);
        $failed++;

        return null;
    }

    return $decoded;
}

/**
 * Classify one live ruleset for the bypass-actor check, structurally.
 *
 * Until the Phase 4B review this was `str_contains(strtolower($name),
 * 'creation')` — a substring match on a field any repository administrator
 * chooses freely. Naming a ruleset "main creation guard" removed it from the
 * scan entirely, so a standing bypass actor on it was never looked at, and the
 * check reported clean.
 *
 * What can actually be classified depends on which endpoint the evidence came
 * from, and this deliberately claims no more than the payload supports:
 *
 *   GET /rulesets        carries `target` and `enforcement`. No `rules`.
 *   GET /rulesets/{id}   additionally carries `rules`.
 *
 * So a creation-only ruleset is only recognisable when `rules` is present. When
 * it is absent the ruleset stays IN SCOPE rather than being guessed at — the
 * cost is an EXTERNAL where the field is also missing, which is the direction
 * that cannot hide a bypass actor.
 *
 * @return array{string, string} one of enforcing|creation_only|not_enforcing|ambiguous, and a label
 */
function classifyRulesetForBypass(mixed $rs, int|string $index): array
{
    $label = 'ruleset #'.(string) $index;

    if (! is_array($rs) || array_is_list($rs)) {
        return ['ambiguous', $label.' (not an object)'];
    }

    if (isset($rs['name']) && is_string($rs['name']) && trim($rs['name']) !== '') {
        $label = '"'.$rs['name'].'"';
    } elseif (isset($rs['id']) && (is_int($rs['id']) || is_string($rs['id']))) {
        $label = 'ruleset id '.(string) $rs['id'];
    } else {
        $label .= ' (unnamed)';
    }

    $enforcement = $rs['enforcement'] ?? null;

    if (! is_string($enforcement)) {
        return ['ambiguous', $label.' (no enforcement field)'];
    }

    // Only the values GitHub documents. An unrecognised one is not assumed
    // harmless — it is assumed unknown.
    if (! in_array($enforcement, ['active', 'evaluate', 'disabled'], true)) {
        return ['ambiguous', $label." (unrecognised enforcement '{$enforcement}')"];
    }

    if ($enforcement !== 'active') {
        return ['not_enforcing', $label." (enforcement={$enforcement})"];
    }

    $target = $rs['target'] ?? null;

    if (! is_string($target)) {
        return ['ambiguous', $label.' (no target field)'];
    }

    if (! in_array($target, ['branch', 'tag', 'push', 'repository'], true)) {
        return ['ambiguous', $label." (unrecognised target '{$target}')"];
    }

    if (! in_array($target, ['branch', 'tag'], true)) {
        return ['not_enforcing', $label." (target={$target}, not branch or tag protection)"];
    }

    // Creation-only is a statement about the RULES, and can only be made when
    // the rules were supplied.
    if (array_key_exists('rules', $rs)) {
        $rules = $rs['rules'];

        if (! is_array($rules) || ! array_is_list($rules)) {
            return ['ambiguous', $label.' (rules present but not a list)'];
        }

        $types = [];

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! isset($rule['type']) || ! is_string($rule['type'])) {
                return ['ambiguous', $label.' (a rule has no usable type)'];
            }

            $types[] = $rule['type'];
        }

        if ($types !== [] && array_unique($types) === ['creation']) {
            return ['creation_only', $label.' (creation-only)'];
        }
    }

    return ['enforcing', $label];
}

/**
 * Does this payload look like a GitHub REST error rather than a resource?
 *
 * Deliberately conservative. `message` alone is not enough — a legitimate
 * payload could carry that key — so a second error-shaped field is required.
 * Being too eager here would reject real evidence, which fails in the
 * direction this validator must never fail in.
 */
function isApiErrorEnvelope(array $decoded): bool
{
    if (array_is_list($decoded)) {
        return false;
    }

    if (! array_key_exists('message', $decoded)) {
        return false;
    }

    return array_key_exists('status', $decoded)
        || array_key_exists('documentation_url', $decoded);
}

function readJson(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("missing file: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("not a JSON object: {$path}");
    }

    return $decoded;
}

/** Find a rule of a given type inside a prepared ruleset artifact. */
function ruleOfType(array $ruleset, string $type): ?array
{
    foreach ($ruleset['rules'] ?? [] as $rule) {
        if (($rule['type'] ?? null) === $type) {
            return $rule;
        }
    }

    return null;
}

echo "EruoFood — repository governance verification\n";
echo str_repeat('=', 72), "\n";
echo "Repository root: {$repoRoot}\n";
echo $rulesetsFile === null
    ? "Live ruleset data: NOT SUPPLIED (GitHub-side checks will be deferred)\n"
    : "Live ruleset data: {$rulesetsFile}\n";

$governanceDir = $repoRoot.'/.github/governance';

// -- 1. Artifacts -------------------------------------------------------------

section('1) Governance artifacts are present and parse');

$requiredArtifacts = [
    'main-ruleset.json',
    'production-tags-ruleset.json',
    'required-checks.json',
    'identities.example.json',
    'ownership.json',
    'main-ruleset.sole-owner.json',
    'README.md',
    'APPLY_GOVERNANCE.md',
    'VERIFY_GOVERNANCE.md',
    'BREAK_GLASS.md',
];

verify('every governance artifact exists', function () use ($governanceDir, $requiredArtifacts): array {
    $missing = array_values(array_filter(
        $requiredArtifacts,
        static fn (string $f): bool => ! is_file($governanceDir.'/'.$f),
    ));

    return [$missing === [], $missing === [] ? count($requiredArtifacts).' artifacts' : 'missing='.implode(',', $missing)];
});

verify('every JSON artifact parses', function () use ($governanceDir): array {
    $files = ['main-ruleset.json', 'main-ruleset.sole-owner.json', 'production-tags-ruleset.json', 'required-checks.json', 'identities.example.json', 'ownership.json'];

    foreach ($files as $file) {
        readJson($governanceDir.'/'.$file);
    }

    return [true, count($files).' files'];
});

// -- 2. main ruleset ----------------------------------------------------------

section('2) The prepared main ruleset encodes the intended policy');

$mainRuleset = null;

try {
    $mainDoc = readJson($governanceDir.'/main-ruleset.json');
    $mainRuleset = $mainDoc['rulesets'][0] ?? null;
} catch (Throwable) {
    $mainRuleset = null;
}

verify('it targets refs/heads/main as an active branch ruleset', function () use ($mainRuleset): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $include = $mainRuleset['conditions']['ref_name']['include'] ?? [];

    return [
        ($mainRuleset['target'] ?? null) === 'branch'
            && ($mainRuleset['enforcement'] ?? null) === 'active'
            && in_array('refs/heads/main', $include, true),
        'target='.($mainRuleset['target'] ?? '?').' enforcement='.($mainRuleset['enforcement'] ?? '?'),
    ];
});

foreach (['deletion' => 'main cannot be deleted', 'non_fast_forward' => 'main cannot be force-pushed'] as $type => $label) {
    verify($label, function () use ($mainRuleset, $type): array {
        return [$mainRuleset !== null && ruleOfType($mainRuleset, $type) !== null, "rule={$type}"];
    });
}

verify('a pull request is required, with the four review protections', function () use ($mainRuleset): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $rule = ruleOfType($mainRuleset, 'pull_request');
    if ($rule === null) {
        return [false, 'no pull_request rule'];
    }

    $p = $rule['parameters'] ?? [];
    $problems = [];

    // The approval count is allowed to be 0 *if* documented — a single-maintainer
    // repository cannot satisfy 1, because GitHub forbids self-approval. What is
    // never acceptable is the rest of the protections being switched off.
    if (! is_int($p['required_approving_review_count'] ?? null)) {
        $problems[] = 'required_approving_review_count not set';
    }
    foreach (['dismiss_stale_reviews_on_push', 'require_code_owner_review', 'require_last_push_approval'] as $flag) {
        if (($p[$flag] ?? false) !== true) {
            $problems[] = $flag.' not true';
        }
    }

    return [
        $problems === [],
        $problems === []
            ? 'approvals='.$p['required_approving_review_count'].', stale dismissal, code-owner review, last-push approval'
            : implode('; ', $problems),
    ];
});

verify('status checks are required, strictly', function () use ($mainRuleset): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $rule = ruleOfType($mainRuleset, 'required_status_checks');
    if ($rule === null) {
        return [false, 'no required_status_checks rule'];
    }

    $p = $rule['parameters'] ?? [];
    $contexts = array_column($p['required_status_checks'] ?? [], 'context');

    return [
        ($p['strict_required_status_checks_policy'] ?? false) === true && $contexts !== [],
        count($contexts).' contexts, strict='.var_export($p['strict_required_status_checks_policy'] ?? null, true),
    ];
});

verify('no standing bypass actor', function () use ($mainRuleset): array {
    // The single most important line in the artifact. A bypass actor is
    // invisible in day-to-day use and removes every rule at once.
    return [
        $mainRuleset !== null && ($mainRuleset['bypass_actors'] ?? null) === [],
        'bypass_actors='.json_encode($mainRuleset['bypass_actors'] ?? null),
    ];
});

verify('linear history is NOT required, preserving the merge-commit workflow', function () use ($mainRuleset): array {
    // docs/ROLLBACK_PLAN.md section 7 reverts the M27 merge with `-m 1`.
    // Requiring linear history would forbid the workflow the project runs on.
    return [$mainRuleset !== null && ruleOfType($mainRuleset, 'required_linear_history') === null, ''];
});

verify('commit signatures are NOT yet required', function () use ($mainRuleset): array {
    // Signing is not configured. Requiring it now would block every commit,
    // including the one that would configure it.
    return [$mainRuleset !== null && ruleOfType($mainRuleset, 'required_signatures') === null, ''];
});

// -- 3. Tag rulesets ----------------------------------------------------------

section('3) The prepared tag rulesets protect the production release path');

$tagRulesets = [];
$tagDoc = [];

try {
    $tagDoc = readJson($governanceDir.'/production-tags-ruleset.json');
    $tagRulesets = $tagDoc['rulesets'] ?? [];
} catch (Throwable) {
    $tagRulesets = [];
}

verify('two tag rulesets are prepared', function () use ($tagRulesets): array {
    // Two, because GitHub scopes bypass_actors to a whole ruleset: the actors
    // allowed to create a release tag must not thereby be allowed to delete one.
    return [count($tagRulesets) === 2, 'count='.count($tagRulesets)];
});

verify('both target refs/tags/v* and are active', function () use ($tagRulesets): array {
    foreach ($tagRulesets as $rs) {
        $include = $rs['conditions']['ref_name']['include'] ?? [];
        if (($rs['target'] ?? null) !== 'tag' || ($rs['enforcement'] ?? null) !== 'active'
            || ! in_array('refs/tags/v*', $include, true)) {
            return [false, 'offending ruleset: '.($rs['name'] ?? '?')];
        }
    }

    return [$tagRulesets !== [], count($tagRulesets).' rulesets'];
});

verify('tag creation is restricted', function () use ($tagRulesets): array {
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'creation') !== null) {
            return [true, 'in "'.($rs['name'] ?? '?').'"'];
        }
    }

    return [false, 'no ruleset carries a creation rule'];
});

verify('tags cannot be deleted or moved', function () use ($tagRulesets): array {
    $found = [];
    foreach ($tagRulesets as $rs) {
        foreach (['deletion', 'non_fast_forward', 'update'] as $type) {
            if (ruleOfType($rs, $type) !== null) {
                $found[$type] = true;
            }
        }
    }

    $missing = array_values(array_diff(['deletion', 'non_fast_forward', 'update'], array_keys($found)));

    return [$missing === [], $missing === [] ? 'deletion, non_fast_forward, update' : 'missing='.implode(',', $missing)];
});

verify('the immutability ruleset has no bypass actor', function () use ($tagRulesets): array {
    // Creation may be bypassed by named release actors. Immutability may not be
    // bypassed by anybody — a release tag its creator can delete is not a record.
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'deletion') !== null && ($rs['bypass_actors'] ?? null) !== []) {
            return [false, '"'.($rs['name'] ?? '?').'" carries bypass actors'];
        }
    }

    return [$tagRulesets !== [], ''];
});

verify('creation authority and immutability are not in the same ruleset', function () use ($tagRulesets): array {
    // M29-B. The failure this forbids does not look like a failure: one ruleset
    // that both restricts creation and forbids deletion has to carry the release
    // actors as bypass_actors to be usable at all — and bypass_actors is scoped
    // to the whole ruleset, so those actors become exempt from deletion and
    // update too. The configuration then reads "release tags are protected" and
    // means "the release actor may delete any release tag".
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'creation') === null) {
            continue;
        }

        foreach (['deletion', 'non_fast_forward', 'update'] as $type) {
            if (ruleOfType($rs, $type) !== null) {
                return [false, '"'.($rs['name'] ?? '?').'" restricts creation and enforces '.$type];
            }
        }
    }

    return [$tagRulesets !== [], 'the two-ruleset split holds'];
});

verify('the creation ruleset carries no bypass actor yet', function () use ($tagRulesets): array {
    // Not a permanent invariant — this is where release actors legitimately go.
    // It is checked so that the day one appears, it appears because somebody
    // decided to put it there, having read production-tags-ruleset.json's
    // actor_placeholder_contract.
    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'creation') !== null && ($rs['bypass_actors'] ?? null) !== []) {
            return [false, '"'.($rs['name'] ?? '?').'" already names release actors — confirm each is intended'];
        }
    }

    return [$tagRulesets !== [], 'no release actor configured yet (tag creation denied to everyone)'];
});

// -- 4. Required checks -------------------------------------------------------

section('4) Required checks exist as jobs, and will report on every pull request');

$requiredChecks = [];

try {
    $checksDoc = readJson($governanceDir.'/required-checks.json');
    $requiredChecks = $checksDoc['required'] ?? [];
} catch (Throwable) {
    $requiredChecks = [];
}

verify('the required list is populated', function () use ($requiredChecks): array {
    return [count($requiredChecks) >= 5, 'count='.count($requiredChecks)];
});

verify('the main ruleset requires exactly the documented contexts', function () use ($mainRuleset, $requiredChecks): array {
    if ($mainRuleset === null) {
        return [false, 'ruleset not readable'];
    }

    $rule = ruleOfType($mainRuleset, 'required_status_checks');
    $inRuleset = array_column($rule['parameters']['required_status_checks'] ?? [], 'context');
    $documented = array_column($requiredChecks, 'context');

    sort($inRuleset);
    sort($documented);

    return [$inRuleset === $documented && $inRuleset !== [], count($inRuleset).' contexts agree'];
});

verify('every required context exists as a job name in the workflow it names', function () use ($requiredChecks, $repoRoot): array {
    $problems = [];

    foreach ($requiredChecks as $check) {
        $context = (string) ($check['context'] ?? '');
        $workflow = $repoRoot.'/'.($check['workflow'] ?? '');

        if (! is_file($workflow)) {
            $problems[] = "{$context}: workflow missing";

            continue;
        }

        // Job names are `    name: <context>`. Compared literally, because a
        // context differing by one character (the U+00B7 middle dot is the
        // usual culprit) never reports, and a required check that never
        // reports blocks every pull request forever.
        $body = (string) file_get_contents($workflow);
        if (! preg_match('/^\s*name:\s*'.preg_quote($context, '/').'\s*$/m', $body)) {
            $problems[] = "{$context}: no matching job name";
        }
    }

    if ($requiredChecks === []) {
        return [false, 'no required checks declared — nothing was verified'];
    }

    return [$problems === [], $problems === [] ? count($requiredChecks).' contexts matched' : implode('; ', $problems)];
});

verify('no required workflow filters its pull_request trigger by path', function () use ($requiredChecks, $repoRoot): array {
    // The trap this whole milestone turns on. GitHub treats a required check
    // that never reports as pending, not satisfied — so a path filter on
    // `pull_request` silently converts a required check into a permanent block
    // on every unrelated pull request.
    $problems = [];

    foreach (array_unique(array_column($requiredChecks, 'workflow')) as $relative) {
        $path = $repoRoot.'/'.$relative;
        if (! is_file($path)) {
            $problems[] = "{$relative}: missing";

            continue;
        }

        $body = (string) file_get_contents($path);

        // Isolate the `pull_request:` block: everything indented beneath it,
        // ignoring comments.
        if (preg_match('/^  pull_request:\s*$\n((?:^(?:    .*|\s*)$\n)*)/m', $body, $m)) {
            $block = preg_replace('/^\s*#.*$/m', '', $m[1]) ?? '';
            if (preg_match('/^\s+paths(-ignore)?:/m', $block)) {
                $problems[] = "{$relative}: pull_request is path-filtered";
            }
        } elseif (! preg_match('/^  pull_request:/m', $body)) {
            $problems[] = "{$relative}: no pull_request trigger";
        }
    }

    $workflows = array_unique(array_column($requiredChecks, 'workflow'));
    if ($workflows === []) {
        return [false, 'no workflows to check — nothing was verified'];
    }

    return [
        $problems === [],
        $problems === [] ? count($workflows).' workflow(s) trigger unconditionally' : implode('; ', $problems),
    ];
});

verify('the known-failing and tag-only workflows are not required', function () use ($requiredChecks): array {
    // GA Docker Certification fails on main today; release.yml is tag-triggered
    // and cannot report on a pull request. Requiring either blocks everything.
    $contexts = array_column($requiredChecks, 'context');
    $workflows = array_column($requiredChecks, 'workflow');

    if ($contexts === []) {
        return [false, 'no required checks declared — nothing was verified'];
    }

    $offenders = array_values(array_filter(
        array_merge($contexts, $workflows),
        static fn (string $v): bool => str_contains($v, 'GA Docker') || str_contains($v, 'release.yml')
            || str_contains($v, 'ga-docker-certification'),
    ));

    return [$offenders === [], $offenders === [] ? count($contexts).' contexts, none excluded-by-policy' : 'wrongly required: '.implode(',', $offenders)];
});

verify('the mobile gate is required as its aggregator, not as its platform jobs', function () use ($requiredChecks): array {
    // M33. Three different strings live in this neighbourhood and exactly one
    // of them belongs in the ruleset:
    //
    //   workflow name  "GA Flutter Certification"   — never a context
    //   job names      "Android · doctor · …", "iOS · analyze · …"
    //                                               — supporting, NOT required
    //   REQUIRED       "Mobile Certification"       — the aggregator job name
    //
    // And separately, `ci-mobile.yml` is the workflow "CI · Mobile (Flutter)"
    // whose job is named "Analyse · Test" — a fourth string, deliberately not
    // required, and the one an earlier reading of this file confused with the
    // certification jobs.
    //
    // Requiring a platform job directly would pin a second byte-exact context
    // containing U+00B7 MIDDLE DOT into the ruleset; a later rename would stop
    // it reporting, and a required check that never reports blocks every pull
    // request. Exact comparison throughout — no str_contains.
    $contexts = array_column($requiredChecks, 'context');

    if ($contexts === []) {
        return [false, 'no required checks declared — nothing was verified'];
    }

    if (! in_array('Mobile Certification', $contexts, true)) {
        return [false, "'Mobile Certification' is not a required context"];
    }

    $mustNotBeRequired = [
        'Android · doctor · analyze · test · build apk',
        'iOS · analyze · test · build (no codesign)',
        'Analyse · Test',
        'GA Flutter Certification',
    ];

    $offenders = array_values(array_intersect($mustNotBeRequired, $contexts));

    return [
        $offenders === [],
        $offenders === []
            ? 'aggregator required; platform jobs and ci-mobile left supporting'
            : 'wrongly required: '.implode(', ', $offenders),
    ];
});

// -- 5. CODEOWNERS ------------------------------------------------------------

section('5) CODEOWNERS claims no owner it cannot resolve');

$codeownersPath = $repoRoot.'/.github/CODEOWNERS';

/** @return list<array{int, string, list<string>}> line number, pattern, owners */
$activeCodeownerRules = static function () use ($codeownersPath): array {
    if (! is_file($codeownersPath)) {
        return [];
    }

    $rules = [];
    foreach (file($codeownersPath, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        $pattern = array_shift($parts);
        $rules[] = [$i + 1, (string) $pattern, array_values($parts)];
    }

    return $rules;
};

verify('the file exists', function () use ($codeownersPath): array {
    return [is_file($codeownersPath), $codeownersPath];
});

verify('no active rule names an owner that cannot resolve', function () use ($activeCodeownerRules, $codeownersPath): array {
    // An owner handle must be @user, @org/team, or an email. Anything else —
    // including a placeholder token — is not a real owner, and a CODEOWNERS
    // file that names unresolvable owners is the defect M29-A found: it reads
    // as configured while enforcing nothing.
    $problems = [];

    foreach ($activeCodeownerRules() as [$line, $pattern, $owners]) {
        if ($owners === []) {
            $problems[] = "line {$line}: '{$pattern}' has no owner";

            continue;
        }

        foreach ($owners as $owner) {
            $looksReal = preg_match('/^@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\/[A-Za-z0-9._-]+)?$/', $owner) === 1
                || filter_var($owner, FILTER_VALIDATE_EMAIL) !== false;

            if (! $looksReal) {
                $problems[] = "line {$line}: '{$owner}' is not a resolvable owner handle";
            }
        }
    }

    if (! is_file($codeownersPath)) {
        return [false, 'CODEOWNERS missing — nothing was verified'];
    }

    $count = count($activeCodeownerRules());

    return [
        $problems === [],
        $problems === []
            ? ($count === 0 ? 'no rule is active (expected while owners are unresolved)' : $count.' active rule(s), all well-formed')
            : implode('; ', $problems),
    ];
});

verify('unresolved ownership is marked, not faked', function () use ($codeownersPath, $activeCodeownerRules): array {
    // Either the domains are still placeholders (and every rule is commented
    // out), or real owners have been supplied (and no placeholder remains).
    // A file with both is half-migrated and will behave unpredictably.
    $body = is_file($codeownersPath) ? (string) file_get_contents($codeownersPath) : '';
    $hasPlaceholders = preg_match('/<OWNER:[A-Z_]+>/', $body) === 1;
    $activeRules = $activeCodeownerRules();

    if ($hasPlaceholders && $activeRules !== []) {
        return [false, 'placeholders and active rules coexist — finish the migration'];
    }

    return [true, $hasPlaceholders ? 'placeholders present, no rule active (expected pre-handover)' : 'no placeholders remain'];
});

// -- 6. Break-glass -----------------------------------------------------------

section('6) Break-glass is documented and auditable');

verify('every required incident field is documented', function () use ($governanceDir): array {
    $path = $governanceDir.'/BREAK_GLASS.md';
    if (! is_file($path)) {
        return [false, 'BREAK_GLASS.md missing'];
    }

    $body = strtoupper((string) file_get_contents($path));
    $fields = [
        'INCIDENT ID', 'REASON', 'RISK ASSESSMENT', 'AUTHORIZED BY',
        'TEMPORARY RULE CHANGE', 'START TIME', 'END TIME', 'ACTION PERFORMED',
        'VERIFICATION', 'RULE RESTORATION', 'POST-INCIDENT REVIEW',
    ];

    $missing = array_values(array_filter($fields, static fn (string $f): bool => ! str_contains($body, $f)));

    return [$missing === [], $missing === [] ? count($fields).' fields' : 'missing='.implode(', ', $missing)];
});

verify('it forbids a standing bypass', function () use ($governanceDir): array {
    $body = (string) @file_get_contents($governanceDir.'/BREAK_GLASS.md');

    return [
        str_contains($body, 'no standing bypass') || str_contains($body, 'No standing bypass')
            || str_contains($body, '**no standing bypass**'),
        '',
    ];
});

// Read before section 7 uses it: the mode decides how several later checks are
// judged, so it has to be established before any of them run.
$appliesTo = is_string($tagDoc['_meta']['applies_to'] ?? null) ? $tagDoc['_meta']['applies_to'] : '';
$ownershipDoc = null;

try {
    $ownershipDoc = readJson($governanceDir.'/ownership.json');
} catch (Throwable) {
    $ownershipDoc = null;
}

$ownership = OwnershipDeclaration::fromArray($ownershipDoc);
$identityPolicy = new IdentityPolicy(explode('/', $appliesTo)[0] ?? '', $ownership->mode);

// -- 7. Governance ownership mode (M29-I) -------------------------------------

section('7) Governance ownership mode');

verify('the ownership declaration is usable', function () use ($ownership): array {
    $errors = $ownership->errors();

    return [
        $errors === [],
        $errors === []
            ? 'mode='.$ownership->mode->value.' owner='.$ownership->repositoryOwner
            : implode('; ', array_map(static fn (IdentityFinding $f): string => $f->code.': '.$f->summary, $errors)),
    ];
});

verify('the declared owner matches the repository the artifacts describe', function () use ($ownership, $appliesTo): array {
    // Two files name the repository. If they ever disagree, the owner-comparison
    // rules are being applied against the wrong account and every result that
    // depends on them is quietly meaningless.
    $expected = explode('/', $appliesTo)[0] ?? '';

    return [
        $expected !== '' && strcasecmp($expected, $ownership->repositoryOwner) === 0,
        "ownership.json={$ownership->repositoryOwner} production-tags-ruleset.json={$expected}",
    ];
});

verify('no participant is an AI assistant or bot', function () use ($ownership): array {
    // Claude and ChatGPT wrote much of this governance. Neither can hold
    // repository access, approve a pull request, or be accountable for a change
    // that moves money — and a synthetic reviewer would satisfy every other
    // check in this file while providing none of the review it simulates.
    $offenders = array_values(array_filter(
        $ownership->humanParticipants,
        static fn (string $h): bool => OwnershipDeclaration::isNonHuman($h),
    ));

    return [$offenders === [], $offenders === [] ? count($ownership->humanParticipants).' human participant(s)' : 'non-human: '.implode(', ', $offenders)];
});

verify('the ruleset for the declared mode matches that mode', function () use ($ownership, $governanceDir): array {
    // The whole point of declaring a mode is that the ruleset actually applied
    // agrees with it. SOLE_OWNER with one required approval blocks every merge;
    // MULTI_PERSON with zero silently discards the review it claims to require.
    $doc = readJson($governanceDir.'/'.$ownership->mode->mainRulesetArtifact());
    $rule = ruleOfType($doc['rulesets'][0] ?? [], 'pull_request');
    $p = $rule['parameters'] ?? [];

    $problems = [];
    $expectedCount = $ownership->mode->requiredApprovingReviewCount();

    if (($p['required_approving_review_count'] ?? null) !== $expectedCount) {
        $problems[] = sprintf('required_approving_review_count=%s, expected %d', json_encode($p['required_approving_review_count'] ?? null), $expectedCount);
    }

    if (($p['require_code_owner_review'] ?? null) !== $ownership->mode->supportsCodeOwnerReview()) {
        $problems[] = 'require_code_owner_review='.json_encode($p['require_code_owner_review'] ?? null);
    }

    return [
        $problems === [],
        $problems === []
            ? $ownership->mode->mainRulesetArtifact().': approvals='.$expectedCount.', code-owner review='.var_export($ownership->mode->supportsCodeOwnerReview(), true)
            : implode('; ', $problems),
    ];
});

verify('the sole-owner ruleset relaxes only the two human-review parameters', function () use ($governanceDir): array {
    // Everything except the review parameters must be byte-identical to the
    // multi-person policy. A "mode" that quietly dropped a status check or
    // opened a bypass would be far worse than no mode at all.
    $multi = readJson($governanceDir.'/main-ruleset.json')['rulesets'][0] ?? [];
    $sole = readJson($governanceDir.'/main-ruleset.sole-owner.json')['rulesets'][0] ?? [];

    $problems = [];

    if (($sole['bypass_actors'] ?? null) !== []) {
        $problems[] = 'sole-owner ruleset carries bypass actors';
    }

    if (array_column($multi['rules'] ?? [], 'type') !== array_column($sole['rules'] ?? [], 'type')) {
        $problems[] = 'rule types differ';
    }

    if (($multi['conditions'] ?? null) !== ($sole['conditions'] ?? null)) {
        $problems[] = 'conditions differ';
    }

    if (ruleOfType($multi, 'required_status_checks') !== ruleOfType($sole, 'required_status_checks')) {
        $problems[] = 'required status checks differ — the automated gates must be identical in both modes';
    }

    $mp = ruleOfType($multi, 'pull_request')['parameters'] ?? [];
    $sp = ruleOfType($sole, 'pull_request')['parameters'] ?? [];

    foreach (array_keys($mp) as $key) {
        $relaxable = in_array($key, ['required_approving_review_count', 'require_code_owner_review', 'require_last_push_approval'], true);

        if (! $relaxable && ($mp[$key] ?? null) !== ($sp[$key] ?? null)) {
            $problems[] = "pull_request.{$key} differs and is not a review parameter";
        }
    }

    return [$problems === [], $problems === [] ? 'only the review parameters differ' : implode('; ', $problems)];
});

foreach ($ownership->mode->summaryLines() as $line) {
    printf("  %s\n", $line);
}

if (! $ownership->mode->supportsIndependentReview()) {
    // Not passed, and since M37 not EXTERNAL either. These three are not
    // "we could not check"; they are "policy says there is nothing to check
    // while one human owns the repository". Calling that EXTERNAL conflated a
    // deliberate deferral with a genuine blind spot and inflated the unverified
    // count from five to eight.
    //
    // The condition is machine-verified, not asserted: ownership.json must
    // parse into a usable declaration AND that declaration must be the
    // single-owner mode. An absent or malformed ownership.json fails
    // isUsable(), and skipCheck() then degrades all three back to EXTERNAL —
    // so a broken policy file can never make checks disappear.
    $soleOwnerVerified = $ownership->isUsable()
        && ! $ownership->mode->supportsIndependentReview();
    $because = 'ownership.json mode='.$ownership->mode->value
        .($ownership->isUsable() ? '' : ' [DECLARATION UNUSABLE]');

    skipCheck('independent human review (requires a second real human)', $soleOwnerVerified, $because, CHECK_POLICY_INDEPENDENT_REVIEW);
    skipCheck('CODEOWNERS enforcement (CODEOWNERS is inert)', $soleOwnerVerified, $because, CHECK_POLICY_CODEOWNERS_ENFORCEMENT);
    skipCheck('finance four-eyes review (one human participant)', $soleOwnerVerified, $because, CHECK_POLICY_FINANCE_FOUR_EYES);
}

// -- 8. Identity configuration and activation readiness (M29-B) ---------------

section('8) Identity configuration and activation readiness');

$identitiesPath = $governanceDir.'/identities.json';
$examplePath = $governanceDir.'/identities.example.json';


$activeIdentities = null;
$identitiesReadable = true;

if (is_file($identitiesPath)) {
    try {
        $activeIdentities = readJson($identitiesPath);
    } catch (Throwable) {
        $identitiesReadable = false;
    }
}

$assessment = $identityPolicy->evaluate(
    $activeIdentities,
    is_file($codeownersPath) ? (string) file_get_contents($codeownersPath) : '',
    array_values(array_filter($tagRulesets, 'is_array')),
);

verify('the identity example is present and parses', function () use ($examplePath): array {
    $doc = readJson($examplePath);

    return [$doc !== [], basename($examplePath)];
});

verify('the example cannot be mistaken for an active configuration', function () use ($examplePath): array {
    // Three independent signals, because this is the mistake with the longest
    // feedback loop: nothing breaks until a real pull request needs a real
    // reviewer, and by then nobody is looking at this file.
    $doc = readJson($examplePath);
    $problems = [];

    if (($doc['_example'] ?? null) !== true) {
        $problems[] = 'no "_example": true marker';
    }

    $body = (string) file_get_contents($examplePath);
    if (! str_contains($body, '<EXAMPLE:')) {
        $problems[] = 'no <EXAMPLE:...> placeholder values';
    }

    if (! str_contains(basename($examplePath), '.example.')) {
        $problems[] = 'filename does not say example';
    }

    return [$problems === [], $problems === [] ? 'marker, placeholders and filename' : implode('; ', $problems)];
});

verify('the example names nobody', function () use ($examplePath, $identityPolicy): array {
    // Run the real policy over the example and require it to be rejected. If the
    // shipped template ever became activatable, somebody would activate it — and
    // a CODEOWNERS full of plausible handles that resolve to nothing is the
    // M29-A defect with better spelling.
    $doc = readJson($examplePath);
    $result = $identityPolicy->evaluate($doc, '', []);

    $codes = array_map(static fn (IdentityFinding $f): string => $f->code, $result->errors());

    return [
        in_array('IDENTITY_EXAMPLE_USED_AS_ACTIVE', $codes, true) && ! $result->isReadyForActivation(),
        'rejected as: '.(implode(', ', $codes) ?: 'nothing — the example is activatable, which it must not be'),
    ];
});

verify('the identity policy agrees CODEOWNERS claims no unresolvable owner', function () use ($assessment): array {
    // A cross-check, not a repeat of section 5. That check uses a deliberately
    // loose handle pattern as a backstop; this one uses the strict rules that
    // gate substitution. Agreement between them is the property worth having —
    // if they ever diverge, a handle could pass the gate and fail the backstop,
    // or worse, the other way round.
    $offenders = array_values(array_filter(
        $assessment->errors(),
        static fn (IdentityFinding $f): bool => $f->code === 'CODEOWNERS_PLACEHOLDER_ACTIVE',
    ));

    return [
        $offenders === [],
        $offenders === []
            ? 'both implementations agree'
            : implode('; ', array_map(static fn (IdentityFinding $f): string => $f->summary, $offenders)),
    ];
});

verify('the active identity configuration, if any, has no errors', function () use ($assessment, $identitiesReadable, $identitiesPath): array {
    if (! $identitiesReadable) {
        return [false, basename($identitiesPath).' does not parse'];
    }

    $errors = $assessment->errors();

    return [
        $errors === [],
        $errors === []
            ? $assessment->state->value
            : implode('; ', array_map(static fn (IdentityFinding $f): string => $f->code.': '.$f->summary, $errors)),
    ];
});

printf(
    "  ACTIVATION STATE  %s — %s\n",
    strtoupper(str_replace('_', ' ', $assessment->state->value)),
    $assessment->state->summary(),
);

foreach (GovernanceRole::cases() as $role) {
    $handles = $assessment->resolved[$role->value] ?? null;
    printf("    %-14s %s\n", $role->value, $handles === null ? 'unresolved' : implode(' ', $handles));
}

foreach ($assessment->warnings() as $warning) {
    printf("  WARNING %s  %s\n", $warning->code, $warning->summary);
}

// Even a flawless identity file leaves these open, and there is no code path
// that removes one. See IdentityAssessment::externalRequirements(). They are
// deferred unless somebody supplies GitHub's own answer; a file in this
// repository is never that answer.
$liveCodeownerErrors = null;

if ($codeownerErrorsFile !== null) {
    // GitHub returns {"errors":[...]} here — an object, never a bare list.
    $liveCodeownerErrors = readEvidence('--codeowners-errors', $codeownerErrorsFile, 'object');
}

foreach ($assessment->externalRequirements() as $requirement) {
    $requirementId = IDENTITY_REQUIREMENT_IDS[$requirement] ?? null;

    if (! str_contains($requirement, 'codeowners/errors') || $liveCodeownerErrors === null) {
        externalCheck($requirement, null, $requirementId);

        continue;
    }

    externalCheck($requirement, function () use ($liveCodeownerErrors, $activeCodeownerRules): array {
        $errors = is_array($liveCodeownerErrors['errors'] ?? null) ? $liveCodeownerErrors['errors'] : [];
        $activeRules = count($activeCodeownerRules());

        // Zero errors is necessary and not sufficient, and the insufficiency is
        // the whole M29-A story: a fully commented-out file also reports zero.
        // Reporting that as a pass would mean this validator confirming review
        // routing works on a file that routes nothing.
        if ($errors !== []) {
            return [false, count($errors).' unknown owner(s): '.json_encode(array_slice($errors, 0, 3))];
        }

        if ($activeRules === 0) {
            return [false, 'zero errors, but no rule is active — a commented-out file also reports zero'];
        }

        return [true, "zero errors across {$activeRules} active rule(s)"];
    }, $requirementId);
}

if ($assessment->state === ActivationState::ReadyForActivation) {
    echo "\n  READY FOR ACTIVATION is a statement about this repository, not about\n";
    echo "  GitHub. Nothing above upgrades an EXTERNAL item to a PASS.\n";
}

// -- 9. What only GitHub can answer -------------------------------------------

section('9) GitHub-side — not provable from this repository');

$liveRulesets = null;

if ($rulesetsFile !== null) {
    // GET /rulesets returns a JSON array. An API error is an object, so
    // demanding a list is the general form of the fix isApiErrorEnvelope()
    // makes specific.
    $liveRulesets = readEvidence('--rulesets', $rulesetsFile, 'list');
}

if ($liveRulesets === null) {
    externalCheck('the main ruleset is actually active on GitHub', null, CHECK_MAIN_RULESET_ACTIVE);
    externalCheck('the production tag rulesets are actually active on GitHub', null, CHECK_TAG_RULESETS_ACTIVE);
    externalCheck('required status checks are enforced by GitHub, not advisory', null, CHECK_REQUIRED_CHECKS_ENFORCED);
    externalCheck('no bypass actors are configured on the live rulesets', null, CHECK_NO_BYPASS_ACTORS);
    externalCheck('branch protection is effective (direct push and force-push refused)', null, CHECK_BRANCH_PROTECTION_EFFECTIVE);
} else {
    $byName = [];
    foreach ($liveRulesets as $rs) {
        if (is_array($rs) && isset($rs['name'])) {
            $byName[(string) $rs['name']] = $rs;
        }
    }

    externalCheck('the main ruleset is actually active on GitHub', function () use ($byName): array {
        foreach ($byName as $name => $rs) {
            if (str_contains(strtolower($name), 'main') && ($rs['enforcement'] ?? null) === 'active') {
                return [true, "\"{$name}\""];
            }
        }

        return [false, 'no active ruleset targeting main; found: '.(implode(', ', array_keys($byName)) ?: 'none')];
    }, CHECK_MAIN_RULESET_ACTIVE);

    externalCheck('the production tag rulesets are actually active on GitHub', function () use ($byName): array {
        $tagRules = array_filter(
            $byName,
            static fn (array $rs): bool => ($rs['target'] ?? null) === 'tag' && ($rs['enforcement'] ?? null) === 'active',
        );

        return [count($tagRules) >= 2, 'active tag rulesets='.count($tagRules)];
    }, CHECK_TAG_RULESETS_ACTIVE);

    // M37 Phase 4B — five outcomes, because there really are five.
    //
    // `GET /repos/{owner}/{repo}/rulesets` does not include `bypass_actors` in
    // its payload. The first implementation read `$rs['bypass_actors'] ?? []`,
    // so an absent key and a genuinely empty one were the same thing, and the
    // check reported PASS on evidence that said nothing at all about bypass
    // actors.
    //
    // The Phase 4B REVIEW then found that fixing the field-level cases left a
    // fifth, and worse, one intact: the loop could examine NOTHING and still
    // fall through to a PASS whose message affirmatively claimed the invariant
    // held "on every enforcing ruleset". Three inputs reached it — an empty
    // array, entries with no `name` (silently dropped before the loop even
    // began), and a set where every ruleset was excluded by the old
    // `str_contains($name, 'creation')` filter. The last is the sharp one: a
    // ruleset named "main creation guard" carrying a standing bypass actor
    // produced `failed=0` with three PASSes.
    //
    //   no enforcing ruleset examined  -> null   EXTERNAL — nothing was checked
    //   a ruleset cannot be classified -> null   EXTERNAL — it might be hiding one
    //   bypass_actors absent           -> null   EXTERNAL — the field was not sent
    //   bypass_actors []               -> PASS   answered, invariant holds
    //   bypass_actors non-empty        -> FAIL   answered, invariant violated
    //   bypass_actors not a list       -> FAIL   answered with something unusable
    //
    // Iterating `$liveRulesets` rather than `$byName` is part of the fix:
    // `$byName` is keyed by name, so an entry without one never appeared here
    // at all. Now it arrives, fails classification, and blocks a PASS.
    externalCheck('no bypass actors on the main or tag-immutability rulesets', function () use ($liveRulesets): array {
        $examined = 0;
        $excluded = 0;
        $offenders = [];
        $malformed = [];
        $silent = [];
        $unclassifiable = [];

        foreach ($liveRulesets as $index => $rs) {
            [$class, $label] = classifyRulesetForBypass($rs, $index);

            if ($class === 'ambiguous') {
                $unclassifiable[] = $label;

                continue;
            }

            if ($class !== 'enforcing') {
                // Structurally out of scope: a ruleset that enforces nothing,
                // or one whose only rule is `creation`, cannot be bypassed in
                // a way that weakens branch or tag protection.
                $excluded++;

                continue;
            }

            $examined++;

            if (! array_key_exists('bypass_actors', $rs)) {
                $silent[] = $label;

                continue;
            }

            $actors = $rs['bypass_actors'];

            if (! is_array($actors) || ! array_is_list($actors)) {
                $malformed[] = $label.' ('.get_debug_type($actors).')';

                continue;
            }

            if ($actors !== []) {
                $offenders[] = $label.' ('.count($actors).')';
            }
        }

        // Precedence: a definite violation outranks an unusable value, which
        // outranks a missing field, which outranks not knowing what we were
        // looking at, which outranks having looked at nothing.
        if ($offenders !== []) {
            return [false, 'bypass actors on: '.implode(', ', $offenders)];
        }

        if ($malformed !== []) {
            return [false, 'bypass_actors is present but not a list on: '.implode(', ', $malformed)];
        }

        if ($silent !== []) {
            return [null, 'no bypass_actors field on: '.implode(', ', $silent)
                .' — GET /rulesets omits it; a missing field is not an empty one'];
        }

        if ($unclassifiable !== []) {
            return [null, sprintf(
                'could not classify %d ruleset(s): %s — an unclassifiable ruleset may carry a bypass actor, so this cannot be reported as clean',
                count($unclassifiable),
                implode(', ', $unclassifiable),
            )];
        }

        // The defect the Phase 4B review caught. Reaching here with nothing
        // examined is not "the invariant holds"; it is "nobody looked".
        if ($examined === 0) {
            return [null, sprintf(
                'no enforcing ruleset was examined (%d supplied, %d structurally out of scope) — an empty examination proves nothing',
                count($liveRulesets),
                $excluded,
            )];
        }

        // Affirmative, and self-evidencing: the count is in the message, so a
        // reader can see how much evidence stands behind the claim.
        return [true, sprintf(
            '%d enforcing ruleset(s) examined; bypass_actors explicitly empty on every one',
            $examined,
        )];
    }, CHECK_NO_BYPASS_ACTORS);

    // Even with live ruleset data these remain unprovable here: the first needs
    // the per-branch rules endpoint, the second needs somebody to try a push.
    externalCheck('required status checks are enforced by GitHub, not advisory', null, CHECK_REQUIRED_CHECKS_ENFORCED);
    externalCheck('branch protection is effective (direct push and force-push refused)', null, CHECK_BRANCH_PROTECTION_EFFECTIVE);
}

// The identity-side externals are reported in section 7, from the assessment
// itself, so they are not repeated here.

// -- Result -------------------------------------------------------------------

echo "\n", str_repeat('=', 72), "\n";
printf(
    "RESULT: %d passed, %d failed, %d external/admin required, %d skipped  [mode=%s]\n",
    $passed,
    $failed,
    $external,
    $skipped,
    $mode,
);

if ($external > 0) {
    echo "\nEXTERNAL items are NOT failures and NOT passes. They are the parts of\n";
    echo "governance that this repository cannot prove anything about. See\n";
    echo ".github/governance/APPLY_GOVERNANCE.md.\n";
}

// Nothing was verified at all. Distinct from "everything passed" and, in
// strict mode, never a success: a suite that checks nothing must not be able
// to report that it checked everything.
$verifiedAnything = $passed > 0 || $failed > 0;
$verificationComplete = $external === 0 && $verifiedAnything;

// Precedence: FAIL outranks UNVERIFIED. A known violation is the sharper
// signal, and reporting "incomplete" while an invariant is broken would bury
// the thing that actually needs fixing.
if ($failed > 0) {
    $exitCode = EXIT_FAIL;
    $exitReason = 'governance_failure';
} elseif ($mode === 'strict' && ! $verificationComplete) {
    $exitCode = EXIT_UNVERIFIED;
    $exitReason = $verifiedAnything ? 'external_unverified' : 'nothing_verified';
} else {
    $exitCode = EXIT_OK;
    $exitReason = 'verified';
}

if ($mode === 'strict' && $exitCode === EXIT_UNVERIFIED) {
    echo "\nSTRICT MODE: verification is incomplete. The following could not be\n";
    echo "verified, and strict mode will not report success without them:\n";

    foreach ($unverifiedItems as $item) {
        printf("  - %s\n", $item);
    }

    echo "\nSupply live evidence with --rulesets= and --codeowners-errors=, or run\n";
    echo "in --mode=advisory while that evidence is unavailable.\n";
}

if ($mode === 'advisory' && $external > 0) {
    printf(
        "\nADVISORY MODE: %d item(s) unverified. Not a failure here, but strict\n"
        ."mode would exit %d on this run.\n",
        $external,
        EXIT_UNVERIFIED,
    );
}

if ($jsonPath !== null) {
    $summary = [
        'schema' => SUMMARY_SCHEMA,
        'mode' => $mode,
        'total' => $passed + $failed + $external + $skipped,
        'passed' => $passed,
        'failed' => $failed,
        'external_unverified' => $external,
        'skipped' => $skipped,
        'error' => 0,
        'verification_complete' => $verificationComplete,
        'exit_code' => $exitCode,
        'exit_reason' => $exitReason,
        'unverified' => array_values($unverifiedItems),

        // Schema 2 (M37 Phase 4B). Every FAIL, with the stable identifier the
        // known-gap ratchet matches on. `id` is null for a check that has no
        // published identifier; the ratchet treats that as an unknown failure
        // and refuses to go green, which is the point.
        'failures' => array_values($failures),

        // The same items as `unverified`, carrying the stable identifier. The
        // ratchet uses this to bound what is allowed to be unverified; if an
        // item it expected to be answered has become unanswerable, that is
        // incomplete verification, not an expected gap.
        'unverified_detail' => array_values($unverifiedDetail),

        // The authoritative identifier set, so the ratchet can reject a
        // known-gap record naming a check that does not exist without keeping
        // a second copy of this list.
        'check_ids' => PUBLISHED_CHECK_IDS,
    ];

    // The summary is written outside this repository by whoever chose the
    // path; nothing writes it by default, and there is no default location.
    if (@file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
        fprintf(STDERR, "ERROR  could not write --json summary to %s\n", $jsonPath);

        exit(EXIT_ERROR);
    }
}

exit($exitCode);
