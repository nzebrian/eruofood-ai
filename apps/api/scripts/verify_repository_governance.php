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
// N-1. Four invariants about what the live tag rulesets CONTAIN, as opposed to
// the one above, which only counts them. `GET /rulesets` cannot answer any of
// these — it carries neither `rules` nor `bypass_actors` — so each is driven
// exclusively by the per-ruleset detail evidence and reports EXTERNAL, never
// PASS, when that evidence is absent or incomplete.
const CHECK_TAG_RULESET_SPLIT = 'github.tag_ruleset_split';
const CHECK_TAG_RULESET_RULES = 'github.tag_ruleset_rules';
const CHECK_TAG_RULESET_BYPASS_EMPTY = 'github.tag_ruleset_bypass_empty';
const CHECK_TAG_RULESET_RELEASE_ACTORS = 'github.tag_ruleset_release_actors';
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
    CHECK_TAG_RULESET_SPLIT,
    CHECK_TAG_RULESET_RULES,
    CHECK_TAG_RULESET_BYPASS_EMPTY,
    CHECK_TAG_RULESET_RELEASE_ACTORS,
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
$rulesetDetailFile = null;
$rulesetDetailsFile = null;
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
    fprintf(STDERR, "              [--rulesets=<path>] [--ruleset-detail=<path>]\n");
    fprintf(STDERR, "              [--ruleset-details=<path>]\n");
    fprintf(STDERR, "              [--codeowners-errors=<path>]\n");
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

    // M50-05 N-4b. A SECOND ruleset flag, and the reason it has to be second is
    // the whole finding: `GET /rulesets` returns `target` and `enforcement` and
    // no `rules` at all, so the list this validator already consumed could never
    // answer which status checks GitHub actually requires. That answer lives
    // only in `GET /rulesets/{id}`. Two endpoints, two pieces of evidence, two
    // flags — collapsing them would mean one missing payload silently degrading
    // the other's checks.
    if (str_starts_with($arg, '--ruleset-detail=')) {
        $rulesetDetailFile = substr($arg, strlen('--ruleset-detail='));

        if ($rulesetDetailFile === '') {
            invocationError('--ruleset-detail= was given an empty path', $jsonPath);
        }

        continue;
    }

    // N-1. The plural form, and the reason it exists is that `--ruleset-detail=`
    // could only ever describe ONE ruleset. The collector pinned a single id, so
    // the tag rulesets — whose `rules` and `bypass_actors` live in exactly the
    // same place as main's — had no evidence source at all, and every question
    // about their contents was unanswerable by construction.
    //
    // This takes the whole set: one detail payload per ruleset the list reports.
    // `--ruleset-detail=` still works and is treated as a one-element set, so
    // every M50-05 N-4b control keeps exercising the code path it was written
    // for. When both are supplied the plural wins, because it is the superset.
    if (str_starts_with($arg, '--ruleset-details=')) {
        $rulesetDetailsFile = substr($arg, strlen('--ruleset-details='));

        if ($rulesetDetailsFile === '') {
            invocationError('--ruleset-details= was given an empty path', $jsonPath);
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
/**
 * The rule types a ruleset detail payload declares, or null when it declares
 * nothing readable.
 *
 * Null and `[]` are deliberately different: a payload with no `rules` key has
 * not told us what it enforces, whereas one with an empty list has told us it
 * enforces nothing. Collapsing them would turn the first into the second and
 * report an unanswered question as an answer.
 *
 * @return list<string>|null
 */
function ruleTypesOf(mixed $detail): ?array
{
    if (! is_array($detail)) {
        return null;
    }

    $rules = $detail['rules'] ?? null;

    if (! is_array($rules) || ! array_is_list($rules)) {
        return null;
    }

    $types = [];

    foreach ($rules as $rule) {
        $type = is_array($rule) ? ($rule['type'] ?? null) : null;

        if (is_string($type) && $type !== '') {
            $types[] = $type;
        }
    }

    return array_values(array_unique($types));
}

/**
 * Comparable keys for a list of ruleset bypass actors.
 *
 * An actor is identified by the PAIR (actor_id, actor_type) — id alone is not
 * unique, because team #5 and integration #5 are different actors. Anything
 * that is not that pair becomes the literal string `unusable`, so a malformed
 * entry can never quietly compare equal to a well-formed one.
 *
 * @param list<mixed> $actors
 * @return list<string>
 */
function actorKeys(array $actors): array
{
    $keys = [];

    foreach ($actors as $actor) {
        $id = is_array($actor) ? ($actor['actor_id'] ?? null) : null;
        $type = is_array($actor) ? ($actor['actor_type'] ?? null) : null;

        $keys[] = is_int($id) && is_string($type) && $type !== ''
            ? $type.'#'.$id
            : 'unusable';
    }

    return $keys;
}

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

verify('the creation ruleset names exactly the recorded release actors', function () use ($tagRulesets, $governanceDir): array {
    // This check used to assert the creation ruleset carried NO actor, so that
    // the day one appeared it appeared because somebody had decided to put it
    // there. N-1 is that day. Asserting emptiness now would either fail forever
    // or have to be deleted, and a check deleted the moment it fires is not a
    // check — so it becomes the durable form of the same question: an actor is
    // here because identities.json says who it should be.
    //
    // Both sides are committed artifacts, so this is a consistency check and
    // never evidence about GitHub. What is actually deployed is answered by the
    // live `github.tag_ruleset_release_actors`, which reads the same two sides
    // from `GET /rulesets/{id}` instead.
    $creation = null;

    foreach ($tagRulesets as $rs) {
        if (ruleOfType($rs, 'creation') !== null) {
            $creation = $rs;

            break;
        }
    }

    if ($creation === null) {
        return [false, 'no ruleset carries a creation rule'];
    }

    $live = $creation['bypass_actors'] ?? null;

    if (! is_array($live) || ! array_is_list($live)) {
        return [false, 'the creation ruleset has no usable bypass_actors list'];
    }

    $identitiesPath = $governanceDir.'/identities.json';

    if (! is_file($identitiesPath)) {
        return $live === []
            ? [true, 'no release actor configured yet (tag creation denied to everyone)']
            : [false, '"'.($creation['name'] ?? '?').'" names release actors that identities.json does not record — it does not exist'];
    }

    $declared = readJson($identitiesPath)['release_actors'] ?? null;

    if (! is_array($declared) || ! array_is_list($declared)) {
        return [false, 'identities.json has no `release_actors` list to compare against'];
    }

    $liveKeys = actorKeys($live);
    $declaredKeys = actorKeys($declared);

    if (in_array('unusable', array_merge($liveKeys, $declaredKeys), true)) {
        return [false, 'a release actor is not a {actor_id:int, actor_type:string} pair — a handle is not an actor id, and GitHub rejects one'];
    }

    $extra = array_values(array_diff($liveKeys, $declaredKeys));
    $absent = array_values(array_diff($declaredKeys, $liveKeys));

    if ($extra !== [] || $absent !== []) {
        $parts = [];

        if ($extra !== []) {
            $parts[] = 'in the ruleset but not in identities.json: '.implode(', ', $extra);
        }

        if ($absent !== []) {
            $parts[] = 'in identities.json but not in the ruleset: '.implode(', ', $absent);
        }

        return [false, implode('; ', $parts)];
    }

    return [true, $liveKeys === []
        ? 'no release actor configured yet (tag creation denied to everyone)'
        : 'the prepared creation ruleset and identities.json agree: '.implode(', ', $liveKeys)];
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

// M50-05 N-4b. `GET /rulesets/{id}` returns a single object carrying `rules`.
// A list here would be the wrong endpoint's payload, so the shape demand is the
// opposite of the one above and is doing real work, not decoration.
//
// N-1 generalises the source without changing that contract. The pool below is
// the set of detail payloads — one per ruleset — however it was supplied:
//
//   --ruleset-details=<list>   the collector's output, every ruleset
//   --ruleset-detail=<map>     one payload, treated as a one-element set
//
// A null pool means no detail evidence of any kind arrived, which every check
// downstream must translate into EXTERNAL rather than a verdict.
$rulesetDetailPool = null;

if ($rulesetDetailsFile !== null) {
    $rulesetDetailPool = readEvidence('--ruleset-details', $rulesetDetailsFile, 'list');
} elseif ($rulesetDetailFile !== null) {
    $one = readEvidence('--ruleset-detail', $rulesetDetailFile, 'map');
    $rulesetDetailPool = $one === null ? null : [$one];
}

// The branch-targeted member of the pool, which is what N-4b has always read.
// Selecting by `target` rather than by position is the only change: a pool that
// now legitimately contains tag rulesets must not let one of them be mistaken
// for main's. Zero or several branch payloads are both "nobody can answer this
// from here" — never a verdict.
$liveRulesetDetail = null;
$branchDetailReason = null;

if ($rulesetDetailPool !== null) {
    $branchDetails = array_values(array_filter(
        $rulesetDetailPool,
        static fn ($d): bool => is_array($d) && ($d['target'] ?? null) === 'branch',
    ));

    if (count($branchDetails) === 1) {
        $liveRulesetDetail = $branchDetails[0];
    } else {
        $branchDetailReason = sprintf(
            '%d branch-targeted ruleset detail payload(s) supplied out of %d — exactly one is needed to answer what main requires',
            count($branchDetails),
            count($rulesetDetailPool),
        );
    }
}

/**
 * The N-4b invariant: what this repository DECLARES it requires, and what
 * GitHub is ACTUALLY enforcing, must be the same set.
 *
 * Returns `null` — EXTERNAL / ADMIN REQUIRED — whenever nobody could look. That
 * is the single most important property here. `.github/governance/required-checks.json`
 * records nine contexts, and a control that fell back to that list when GitHub
 * did not answer would be grading GitHub against a file this repository wrote:
 * a green tick proving only that we can read our own JSON. The declared set is
 * one side of the comparison and is never evidence for the other.
 *
 * Everything is compared as a SET. Ordering is not asserted because GitHub does
 * not treat required checks as a sequence, and asserting it would produce
 * failures that mean nothing. Count is not asserted either: "9" is today's
 * answer, not the property. `declared == live` stays correct after the tenth
 * check is added, and a hard-coded number would not.
 *
 * @return callable():array{bool|null, string}|null
 */
$requiredChecksInvariant = null;

if ($liveRulesetDetail === null && $branchDetailReason !== null) {
    // Detail evidence arrived but none of it describes a branch ruleset. That is
    // a reason worth printing, so the reader is not left with a bare EXTERNAL.
    $requiredChecksInvariant = static fn (): array => [null, $branchDetailReason];
}

if ($liveRulesetDetail !== null) {
    $requiredChecksInvariant = static function () use ($liveRulesetDetail, $liveRulesets, $governanceDir): array {
        // --- is this detail payload the WHOLE live answer? ----------------
        // The collector fetches one ruleset by id. GitHub allows several branch
        // rulesets to apply to the same ref and aggregates their required
        // checks, so a second active branch ruleset would add contexts this
        // payload never mentions — and the comparison below would then be
        // exact about an incomplete set, which is the worst kind of green.
        //
        // The list evidence already collected answers "how many are there".
        // More than one, or a list that does not contain the ruleset actually
        // fetched, means nobody can claim completeness: EXTERNAL, not PASS.
        if (is_array($liveRulesets)) {
            $activeBranch = array_values(array_filter(
                $liveRulesets,
                static fn ($rs): bool => is_array($rs)
                    && ($rs['target'] ?? null) === 'branch'
                    && ($rs['enforcement'] ?? null) === 'active',
            ));

            if (count($activeBranch) !== 1) {
                return [null, sprintf(
                    '%d active branch ruleset(s) exist; required checks aggregate across them, so one detail payload cannot answer this',
                    count($activeBranch),
                )];
            }

            $listedId = $activeBranch[0]['id'] ?? null;
            $detailId = $liveRulesetDetail['id'] ?? null;

            if ($listedId !== null && $detailId !== null && $listedId !== $detailId) {
                return [null, sprintf(
                    'detail payload is ruleset #%s but the only active branch ruleset is #%s — the wrong one was fetched',
                    (string) $detailId,
                    (string) $listedId,
                )];
            }
        }

        // --- the live side -----------------------------------------------
        $enforcement = $liveRulesetDetail['enforcement'] ?? null;
        $target = $liveRulesetDetail['target'] ?? null;

        if ($target !== 'branch') {
            return [null, sprintf(
                'the supplied ruleset detail targets %s, not branch — it cannot answer what main requires',
                is_string($target) ? "`{$target}`" : 'nothing recognisable',
            )];
        }

        // A ruleset that is not active enforces nothing, so this is answered
        // and answered badly: every declared context is unenforced.
        if ($enforcement !== 'active') {
            return [false, sprintf(
                'ruleset "%s" is enforcement=%s — required checks are not being enforced at all',
                (string) ($liveRulesetDetail['name'] ?? 'unnamed'),
                is_string($enforcement) ? $enforcement : 'absent',
            )];
        }

        $rules = $liveRulesetDetail['rules'] ?? null;

        if (! is_array($rules) || ! array_is_list($rules)) {
            return [null, '`rules` is absent or not a list — this payload does not answer which checks are required'];
        }

        $liveRaw = null;

        foreach ($rules as $rule) {
            if (is_array($rule) && ($rule['type'] ?? null) === 'required_status_checks') {
                $liveRaw = $rule['parameters']['required_status_checks'] ?? null;

                break;
            }
        }

        // The rules array arrived and carries no required_status_checks rule.
        // That is answered: GitHub requires nothing.
        if ($liveRaw === null) {
            return [false, 'the active branch ruleset carries no required_status_checks rule — GitHub requires no status check at all'];
        }

        if (! is_array($liveRaw) || ! array_is_list($liveRaw)) {
            return [null, 'required_status_checks parameters are not a list — unusable evidence'];
        }

        $live = [];

        foreach ($liveRaw as $i => $entry) {
            if (! is_array($entry) || ! array_key_exists('context', $entry)) {
                return [false, sprintf('live required check #%d is malformed (no `context` key)', $i)];
            }

            $ctx = $entry['context'];

            if (! is_string($ctx) || trim($ctx) === '') {
                return [false, sprintf('live required check #%d has a malformed context (%s)', $i, get_debug_type($ctx))];
            }

            $live[] = $ctx;
        }

        // --- the declared side -------------------------------------------
        $declaredDoc = readJson($governanceDir.'/required-checks.json');
        $declaredList = $declaredDoc['required'] ?? null;

        if (! is_array($declaredList) || ! array_is_list($declaredList)) {
            return [false, 'required-checks.json has no `required` list — the declared side of the invariant is malformed'];
        }

        $declared = [];

        foreach ($declaredList as $i => $entry) {
            $ctx = is_array($entry) ? ($entry['context'] ?? null) : null;

            if (! is_string($ctx) || trim($ctx) === '') {
                return [false, sprintf('declared required check #%d is malformed (no usable `context`)', $i)];
            }

            $declared[] = $ctx;
        }

        // --- duplicates, on either side ----------------------------------
        // A duplicate is not harmless noise: it makes `count()` disagree with
        // the set size, so any control that compared counts would report a
        // difference that is not there, or miss one that is.
        foreach ([['live', $live], ['declared', $declared]] as [$side, $list]) {
            $dupes = array_keys(array_filter(array_count_values($list), static fn (int $n): bool => $n > 1));

            if ($dupes !== []) {
                return [false, sprintf('%s required checks contain duplicates: %s', $side, implode(', ', $dupes))];
            }
        }

        // --- exact set equality ------------------------------------------
        $missing = array_values(array_diff($declared, $live));
        $unexpected = array_values(array_diff($live, $declared));

        if ($missing !== [] || $unexpected !== []) {
            $parts = [];

            if ($missing !== []) {
                $parts[] = 'declared but NOT enforced: '.implode(', ', array_map(static fn ($c) => "\"{$c}\"", $missing));
            }

            if ($unexpected !== []) {
                $parts[] = 'enforced but NOT declared: '.implode(', ', array_map(static fn ($c) => "\"{$c}\"", $unexpected));
            }

            return [false, implode('; ', $parts)];
        }

        return [true, sprintf(
            'declared and live required-check sets are identical (%d context(s), ruleset "%s")',
            count($declared),
            (string) ($liveRulesetDetail['name'] ?? 'unnamed'),
        )];
    };
}

// -- N-1: what the live TAG rulesets actually contain --------------------------
//
// `github.tag_rulesets_active` counts them. These four read them.
//
// The distinction matters because the count is the easy half. Two active tag
// rulesets can exist and still protect nothing: a `creation` rule with a
// standing bypass actor, an immutability ruleset missing `update` so a tag can
// be moved but not deleted, a ref pattern narrowed to `refs/tags/v9*`. Every
// one of those reads as "two active tag rulesets" in the list payload, and
// every one of them is the whole protection gone.
//
// The evidence contract is the N-4b one, unchanged:
//
//   PASS      complete positive evidence — the invariant was checked and holds
//   FAIL      evidence exists and contradicts the invariant
//   EXTERNAL  evidence is required and is absent, partial or unusable
//
// `production-tags-ruleset.json` is NEVER consulted for any of it. That file is
// what this repository INTENDS; comparing GitHub against it would be a valid
// design check but is not evidence of enforcement, and reporting it as such is
// exactly the class of vacuous PASS M37 Phase 4B and M50-05 N-4b each had to
// remove once already.

/**
 * Resolve the live tag rulesets from the two evidence sources, or explain why
 * nobody can.
 *
 * @return array{0: 'external'|'ready', 1: string, 2: list<array<string, mixed>>}
 */
$resolveTagDetails = static function () use ($liveRulesets, $rulesetDetailPool): array {
    if ($rulesetDetailPool === null) {
        return ['external', 'no ruleset detail evidence was supplied — `GET /rulesets` carries neither `rules` nor `bypass_actors`, so the list alone cannot answer this', []];
    }

    if ($liveRulesets === null) {
        return ['external', 'the ruleset list is absent, so there is no way to tell whether the detail payloads are the complete set', []];
    }

    // Every TAG-targeted ruleset, not only the active ones. An inactive tag
    // ruleset is a real answer — it enforces nothing — and filtering it out
    // here would turn "protection was switched off" into "nothing to see".
    $listedTags = array_values(array_filter(
        $liveRulesets,
        static fn ($rs): bool => is_array($rs) && ($rs['target'] ?? null) === 'tag',
    ));

    if ($listedTags === []) {
        return ['external', 'no tag ruleset exists on GitHub — there is no content to assess; `github.tag_rulesets_active` is the check that reports that absence', []];
    }

    $byId = [];

    foreach ($rulesetDetailPool as $detail) {
        if (is_array($detail) && isset($detail['id'])) {
            $byId[(string) $detail['id']] = $detail;
        }
    }

    $resolved = [];
    $missing = [];

    foreach ($listedTags as $rs) {
        $id = $rs['id'] ?? null;

        if ($id === null) {
            return ['external', 'a listed tag ruleset carries no `id`, so its detail payload cannot be matched to it', []];
        }

        if (! isset($byId[(string) $id])) {
            $missing[] = '#'.(string) $id;

            continue;
        }

        $resolved[] = $byId[(string) $id];
    }

    if ($missing !== []) {
        return ['external', sprintf(
            'no detail payload for tag ruleset(s) %s — the set is incomplete, and a partial read of tag protection is not a verdict',
            implode(', ', $missing),
        ), []];
    }

    return ['ready', '', $resolved];
};

/**
 * Split the resolved tag rulesets into the creation one and the immutability
 * one, by the rules they carry rather than by their names.
 *
 * Names are administrator-supplied free text; a policy that keyed off them
 * could be defeated by a rename, which is not a security boundary. The rule
 * types are the thing GitHub actually enforces.
 *
 * @param list<array<string, mixed>> $details
 * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: ?string}
 */
$classifyTagRulesets = static function (array $details): array {
    $immutabilityRules = ['deletion', 'non_fast_forward', 'update'];
    $creation = null;
    $immutable = null;

    foreach ($details as $detail) {
        $types = ruleTypesOf($detail);

        if ($types === null) {
            return [null, null, sprintf(
                'ruleset "%s" has no usable `rules` list, so it cannot be classified',
                (string) ($detail['name'] ?? 'unnamed'),
            )];
        }

        if (array_intersect($types, $immutabilityRules) !== []) {
            if ($immutable !== null) {
                return [null, null, 'two rulesets both carry immutability rules — the creation/immutability split is not what is deployed'];
            }

            $immutable = $detail;

            continue;
        }

        if ($creation !== null) {
            return [null, null, 'two rulesets carry neither deletion, non_fast_forward nor update — neither can be the immutability ruleset'];
        }

        $creation = $detail;
    }

    if ($creation === null || $immutable === null) {
        return [null, null, 'the two rulesets do not divide into one creation ruleset and one immutability ruleset'];
    }

    return [$creation, $immutable, null];
};

$tagSplitInvariant = static function () use ($resolveTagDetails, $classifyTagRulesets): array {
    [$state, $reason, $details] = $resolveTagDetails();

    if ($state === 'external') {
        return [null, $reason];
    }

    if (count($details) !== 2) {
        return [false, sprintf(
            '%d tag ruleset(s) exist; the design is exactly two — one restricting creation, one making tags immutable. GitHub scopes bypass_actors to a whole ruleset, so collapsing them would exempt the release actors from deletion as well',
            count($details),
        )];
    }

    foreach ($details as $detail) {
        $name = (string) ($detail['name'] ?? 'unnamed');

        if (($detail['enforcement'] ?? null) !== 'active') {
            return [false, sprintf(
                'ruleset "%s" is enforcement=%s — it is deployed but enforces nothing',
                $name,
                is_string($detail['enforcement'] ?? null) ? (string) $detail['enforcement'] : 'absent',
            )];
        }

        $include = $detail['conditions']['ref_name']['include'] ?? null;

        if (! is_array($include) || ! array_is_list($include)) {
            return [null, sprintf('ruleset "%s" carries no readable conditions.ref_name.include — the payload does not say which tags it governs', $name)];
        }

        if (! in_array('refs/tags/v*', $include, true)) {
            return [false, sprintf(
                'ruleset "%s" does not include `refs/tags/v*` (includes: %s) — release.yml fires on `v*.*.*`, so any narrower pattern leaves release tags ungoverned',
                $name,
                implode(', ', array_map(static fn ($p): string => is_string($p) ? $p : get_debug_type($p), $include)) ?: 'none',
            )];
        }
    }

    [, , $splitError] = $classifyTagRulesets($details);

    if ($splitError !== null) {
        return [false, $splitError];
    }

    return [true, 'two active tag rulesets, both governing refs/tags/v*, split into creation and immutability'];
};

$tagRulesInvariant = static function () use ($resolveTagDetails, $classifyTagRulesets): array {
    [$state, $reason, $details] = $resolveTagDetails();

    if ($state === 'external') {
        return [null, $reason];
    }

    [$creation, $immutable, $splitError] = $classifyTagRulesets($details);

    if ($splitError !== null) {
        // The split check reports this as a FAIL. Repeating it here would be a
        // second failure for one cause; what this check can honestly say is
        // that it could not be evaluated.
        return [null, 'the creation/immutability split could not be established (see github.tag_ruleset_split), so which ruleset should carry which rule is undecidable'];
    }

    $creationTypes = ruleTypesOf($creation) ?? [];
    $immutableTypes = ruleTypesOf($immutable) ?? [];

    if (! in_array('creation', $creationTypes, true)) {
        return [false, sprintf(
            'the non-immutability tag ruleset ("%s") carries no `creation` rule (rules: %s) — nothing restricts who may cut a release tag',
            (string) ($creation['name'] ?? 'unnamed'),
            implode(', ', $creationTypes) ?: 'none',
        )];
    }

    $expected = ['deletion', 'non_fast_forward', 'update'];
    sort($immutableTypes);

    if ($immutableTypes !== $expected) {
        $absent = array_values(array_diff($expected, $immutableTypes));
        $extra = array_values(array_diff($immutableTypes, $expected));
        $parts = [];

        if ($absent !== []) {
            $parts[] = 'missing '.implode(', ', $absent);
        }

        if ($extra !== []) {
            $parts[] = 'unexpected '.implode(', ', $extra);
        }

        return [false, sprintf(
            'the immutability ruleset ("%s") must carry exactly deletion + non_fast_forward + update: %s. Without `update` a tag can be moved; without `non_fast_forward` it can be rewritten; without `deletion` it can be removed and recreated',
            (string) ($immutable['name'] ?? 'unnamed'),
            implode('; ', $parts),
        )];
    }

    return [true, 'creation is restricted, and the immutability ruleset carries deletion + non_fast_forward + update'];
};

$tagBypassEmptyInvariant = static function () use ($resolveTagDetails, $classifyTagRulesets): array {
    [$state, $reason, $details] = $resolveTagDetails();

    if ($state === 'external') {
        return [null, $reason];
    }

    [$creation, $immutable, $splitError] = $classifyTagRulesets($details);

    if ($splitError !== null) {
        return [null, 'the creation/immutability split could not be established (see github.tag_ruleset_split), so which ruleset must be actor-free is undecidable'];
    }

    // The absent-versus-empty distinction M37 Phase 4B established. `GET
    // /rulesets/{id}` does send bypass_actors, but a payload that omits it is
    // still not evidence of emptiness.
    if (! array_key_exists('bypass_actors', $immutable)) {
        return [null, sprintf(
            'the immutability ruleset ("%s") payload carries no `bypass_actors` field — a missing field is not an empty one',
            (string) ($immutable['name'] ?? 'unnamed'),
        )];
    }

    $immutableActors = $immutable['bypass_actors'];

    if (! is_array($immutableActors) || ! array_is_list($immutableActors)) {
        return [false, sprintf(
            'the immutability ruleset ("%s") has a bypass_actors that is not a list (%s)',
            (string) ($immutable['name'] ?? 'unnamed'),
            get_debug_type($immutableActors),
        )];
    }

    if ($immutableActors !== []) {
        return [false, sprintf(
            'the immutability ruleset ("%s") has %d bypass actor(s) — a release tag its creator can delete is not an immutable release record, and this ruleset exists for no other reason',
            (string) ($immutable['name'] ?? 'unnamed'),
            count($immutableActors),
        )];
    }

    // Disjointness. Authority to create a release tag is not authority to unmake
    // one; an actor holding both has the immutability ruleset switched off for
    // them personally, which reads as correct in each ruleset examined alone.
    if (! array_key_exists('bypass_actors', $creation)) {
        return [null, sprintf(
            'the immutability ruleset is actor-free, but the creation ruleset ("%s") payload carries no `bypass_actors` field, so the two sets cannot be compared',
            (string) ($creation['name'] ?? 'unnamed'),
        )];
    }

    $creationActors = $creation['bypass_actors'];

    if (! is_array($creationActors) || ! array_is_list($creationActors)) {
        return [false, sprintf(
            'the creation ruleset ("%s") has a bypass_actors that is not a list (%s)',
            (string) ($creation['name'] ?? 'unnamed'),
            get_debug_type($creationActors),
        )];
    }

    $both = array_intersect(actorKeys($creationActors), actorKeys($immutableActors));

    if ($both !== []) {
        return [false, 'the same actor holds creation authority and immutability bypass: '.implode(', ', $both)];
    }

    return [true, 'the immutability ruleset has an explicitly empty bypass_actors, and no actor appears in both rulesets'];
};

$tagReleaseActorsInvariant = static function () use ($resolveTagDetails, $classifyTagRulesets, $governanceDir): array {
    [$state, $reason, $details] = $resolveTagDetails();

    if ($state === 'external') {
        return [null, $reason];
    }

    [$creation, , $splitError] = $classifyTagRulesets($details);

    if ($splitError !== null) {
        return [null, 'the creation/immutability split could not be established (see github.tag_ruleset_split), so the creation ruleset cannot be identified'];
    }

    $identitiesPath = $governanceDir.'/identities.json';

    // No identities file means nobody has yet decided who may cut a release. The
    // declared side of the comparison does not exist, and inventing it — from a
    // username, from "whoever is an admin", from the actors GitHub happens to
    // report — is the failure mode M29-B exists to prevent.
    if (! is_file($identitiesPath)) {
        return [null, 'no .github/governance/identities.json — the release actor set has not been decided, so there is nothing to compare the live bypass actors against (RELEASE_ACTOR_ID_REQUIRED)'];
    }

    $identities = readJson($identitiesPath);
    $declaredActors = $identities['release_actors'] ?? null;

    if (! is_array($declaredActors) || ! array_is_list($declaredActors)) {
        return [false, 'identities.json has no `release_actors` list — the declared side of this invariant is malformed'];
    }

    if (! array_key_exists('bypass_actors', $creation)) {
        return [null, sprintf(
            'the creation ruleset ("%s") payload carries no `bypass_actors` field, so what GitHub grants cannot be compared with what identities.json records',
            (string) ($creation['name'] ?? 'unnamed'),
        )];
    }

    $liveActors = $creation['bypass_actors'];

    if (! is_array($liveActors) || ! array_is_list($liveActors)) {
        return [false, sprintf('the creation ruleset has a bypass_actors that is not a list (%s)', get_debug_type($liveActors))];
    }

    $declaredKeys = actorKeys($declaredActors);
    $liveKeys = actorKeys($liveActors);

    foreach ([['declared', $declaredKeys], ['live', $liveKeys]] as [$side, $keys]) {
        if (in_array('unusable', $keys, true)) {
            return [false, sprintf('a %s release actor is not a {actor_id:int, actor_type:string} pair — a handle is not an actor id, and GitHub rejects one', $side)];
        }
    }

    $unrecorded = array_values(array_diff($liveKeys, $declaredKeys));
    $ungranted = array_values(array_diff($declaredKeys, $liveKeys));

    if ($unrecorded !== [] || $ungranted !== []) {
        $parts = [];

        if ($unrecorded !== []) {
            $parts[] = 'granted on GitHub but NOT recorded in identities.json: '.implode(', ', $unrecorded);
        }

        if ($ungranted !== []) {
            $parts[] = 'recorded in identities.json but NOT granted on GitHub: '.implode(', ', $ungranted);
        }

        return [false, implode('; ', $parts)];
    }

    return [true, sprintf(
        'the creation ruleset grants exactly the %d release actor(s) identities.json records',
        count($declaredKeys),
    )];
};

if ($liveRulesets === null) {
    externalCheck('the main ruleset is actually active on GitHub', null, CHECK_MAIN_RULESET_ACTIVE);
    externalCheck('the production tag rulesets are actually active on GitHub', null, CHECK_TAG_RULESETS_ACTIVE);
    // N-1. Evaluated in both arms so the four content invariants always appear
    // in the report and in the summary's id set. With no list they resolve to
    // EXTERNAL on their own terms — completeness cannot be established — rather
    // than vanishing, which would let a missing payload shrink the check set.
    externalCheck('the tag rulesets are split into creation and immutability', $tagSplitInvariant, CHECK_TAG_RULESET_SPLIT);
    externalCheck('the tag rulesets carry the rules that make a release tag immutable', $tagRulesInvariant, CHECK_TAG_RULESET_RULES);
    externalCheck('the tag-immutability ruleset has an explicitly empty bypass_actors', $tagBypassEmptyInvariant, CHECK_TAG_RULESET_BYPASS_EMPTY);
    externalCheck('release-tag creation is granted to exactly the recorded actors', $tagReleaseActorsInvariant, CHECK_TAG_RULESET_RELEASE_ACTORS);
    // N-4b is driven by the DETAIL endpoint, which is fetched independently of
    // the list. It is evaluated here too so that a missing list cannot suppress
    // an answer the detail payload can give on its own.
    externalCheck('required status checks match the declared set exactly', $requiredChecksInvariant, CHECK_REQUIRED_CHECKS_ENFORCED);
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

    // N-1. The count above is necessary and nowhere near sufficient; these four
    // read what those rulesets actually say.
    externalCheck('the tag rulesets are split into creation and immutability', $tagSplitInvariant, CHECK_TAG_RULESET_SPLIT);
    externalCheck('the tag rulesets carry the rules that make a release tag immutable', $tagRulesInvariant, CHECK_TAG_RULESET_RULES);
    externalCheck('the tag-immutability ruleset has an explicitly empty bypass_actors', $tagBypassEmptyInvariant, CHECK_TAG_RULESET_BYPASS_EMPTY);
    externalCheck('release-tag creation is granted to exactly the recorded actors', $tagReleaseActorsInvariant, CHECK_TAG_RULESET_RELEASE_ACTORS);

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
    // N-2. The evidence source this check has always been missing.
    //
    // The note in known-gaps.json named the remedy exactly: "Closing this gap
    // needs an evidence source that actually carries the field." `GET /rulesets`
    // never will — the omission is structural, not a permission. `GET
    // /rulesets/{id}` does carry it, to a caller holding `Administration: read`,
    // and the advisory workflow now fetches one per ruleset as the release App.
    //
    // So each live ruleset is enriched from its own detail payload, matched by
    // id, before classification. Nothing else moves: the four-way discrimination
    // below is untouched, classification still runs on the merged record, and a
    // run with no detail evidence — a fork pull request among them — sees
    // exactly what it saw before and reports EXTERNAL. Only a payload that
    // genuinely carries the field can change the outcome, which is the property
    // that made the field absent-vs-empty distinction worth having.
    // `rules` is merged alongside `bypass_actors`, and that is not incidental.
    // classifyRulesetForBypass() excludes a ruleset whose only rule is
    // `creation` — an actor exempt from it can create a ref, not delete or move
    // one — but the list payload carries no `rules`, so the creation ruleset
    // cannot be recognised as creation-only and is treated as enforcing. Supply
    // `bypass_actors` without `rules` and the release App's legitimate grant
    // reads as a standing bypass: a FAIL, on a repository that is correctly
    // configured. The two fields answer one question and travel together.
    $detailsById = [];

    foreach (($rulesetDetailPool ?? []) as $detail) {
        if (is_array($detail) && isset($detail['id'])) {
            $detailsById[(string) $detail['id']] = $detail;
        }
    }

    externalCheck('no bypass actors on the main or tag-immutability rulesets', function () use ($liveRulesets, $detailsById): array {
        $examined = 0;
        $excluded = 0;
        $offenders = [];
        $malformed = [];
        $silent = [];
        $unclassifiable = [];

        foreach ($liveRulesets as $index => $rs) {
            // Merged only when the detail payload actually carried the key, so
            // a detail read that came back without it leaves the list entry
            // exactly as silent as it already was.
            if (is_array($rs) && isset($rs['id']) && isset($detailsById[(string) $rs['id']])) {
                $detail = $detailsById[(string) $rs['id']];

                foreach (['rules', 'bypass_actors'] as $field) {
                    if (array_key_exists($field, $detail)) {
                        $rs[$field] = $detail[$field];
                    }
                }
            }

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

    // M50-05 N-4b closed the first of these two. It said, correctly, that the
    // required-check question "needs the per-branch rules endpoint" — the list
    // consumed above carries no `rules`. That endpoint is now fetched as its own
    // evidence file and drives the check below; when it is absent or unreadable
    // this stays EXTERNAL exactly as it was, because a control that guessed
    // would be worse than one that admits it does not know.
    //
    // The second still needs somebody to try a push, and no payload answers it.
    externalCheck('required status checks match the declared set exactly', $requiredChecksInvariant, CHECK_REQUIRED_CHECKS_ENFORCED);
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
