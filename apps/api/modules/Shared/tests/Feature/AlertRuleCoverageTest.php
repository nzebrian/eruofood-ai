<?php

declare(strict_types=1);

/**
 * M28 Phase 5 — the alert rules, checked for the things that make them useful.
 *
 * Prometheus will happily load a rule that fires at 3am and says
 * "ReconciliationBacklog" and nothing else. The responder then has to find
 * somebody who knows what that means. So every financial and security rule must
 * carry an owner, a severity and a written response, and each signal M28
 * requires must have a rule — asserted by name, because "we have monitoring" is
 * the claim that goes stale first.
 *
 * ## Why this reads the file as text
 *
 * There is no YAML parser in this project's dependency tree — no ext-yaml, no
 * symfony/yaml — and adding one to satisfy a test would be a production
 * dependency bought for a test's convenience. The file has a fixed, regular
 * shape that it is committed to keeping, so the scan below splits it on rule
 * boundaries and checks each block. If the file's structure ever changes enough
 * to defeat this, `alertBlocks()` returns nothing and the first assertion fails
 * loudly rather than the suite passing on an empty set.
 */
function alertRulesText(): string
{
    $path = dirname(base_path(), 2).'/infra/monitoring/alert-rules.yaml';

    expect(file_exists($path))->toBeTrue('infra/monitoring/alert-rules.yaml is missing');

    return (string) file_get_contents($path);
}

/**
 * Every rule in one group, as name => the raw YAML block declaring it.
 *
 * @return array<string, string>
 */
function alertBlocks(string $group): array
{
    $text = alertRulesText();

    $start = strpos($text, "- name: {$group}");
    if ($start === false) {
        return [];
    }

    // The group ends at the next group header at the same indentation, or EOF.
    $next = strpos($text, "\n  - name: ", $start + 1);
    $section = $next === false ? substr($text, $start) : substr($text, $start, $next - $start);

    $chunks = preg_split('/^\s*- alert:\s*/m', $section) ?: [];
    array_shift($chunks); // everything before the first rule

    $blocks = [];
    foreach ($chunks as $chunk) {
        $name = trim(strtok($chunk, "\n") ?: '');
        if ($name !== '') {
            $blocks[$name] = $chunk;
        }
    }

    return $blocks;
}

it('finds rules in both new groups, so the scan is not vacuous', function (): void {
    // Guards the parsing itself. Every assertion below is a filter over these
    // two sets; if the scan silently returned nothing, they would all pass.
    expect(alertBlocks('eruofood-financial'))->not->toBeEmpty()
        ->and(alertBlocks('eruofood-security'))->not->toBeEmpty();
});

it('still declares the pre-M28 infrastructure groups', function (string $group): void {
    // M28 adds groups; it must not have replaced the ones M21 shipped.
    expect(alertBlocks($group))->not->toBeEmpty();
})->with(['eruofood-api', 'eruofood-postgres', 'eruofood-redis', 'eruofood-queue-workers', 'eruofood-infra']);

it('covers every financial signal M28 requires', function (string $alert): void {
    expect(array_keys(alertBlocks('eruofood-financial')))->toContain($alert);
})->with([
    'settlement failures' => 'SettlementFailureRate',
    'UNKNOWN transfer outcomes' => 'SettlementUnknownOutcome',
    'reconciliation backlog' => 'ReconciliationBacklog',
    'duplicate payout attempts' => 'DuplicatePayoutAttempted',
    'ledger imbalance' => 'LedgerImbalance',
    'payable mismatch' => 'PayableDrift',
    'provider communication failures' => 'PaymentProviderUnreachable',
    'abnormal payout volume' => 'AbnormalPayoutVolume',
]);

it('covers every security signal M28 requires', function (string $alert): void {
    expect(array_keys(alertBlocks('eruofood-security')))->toContain($alert);
})->with([
    'failed privileged access' => 'PrivilegedFinancialAccessDenied',
    'suspicious financial actions' => 'FinancialActionOutsideBusinessHours',
    'break-glass access' => 'BreakGlassAccessUsed',
    'configuration changes' => 'ConfigurationChangeUnverified',
]);

it('gives every financial and security alert an owner, a severity and a response', function (string $group): void {
    // The difference between an alert and a page nobody can act on.
    // str_contains rather than expect()->toContain(): Pest's toContain takes
    // variadic needles, so a "message" passed as the second argument is quietly
    // asserted as another needle and the failure reads as a missing owner.
    foreach (alertBlocks($group) as $name => $block) {
        expect(str_contains($block, 'owner:'))->toBeTrue("{$name} has no owner")
            ->and(str_contains($block, 'response:'))
            ->toBeTrue("{$name} tells a responder nothing about what to do")
            ->and(str_contains($block, 'summary:'))->toBeTrue("{$name} has no summary")
            ->and(preg_match('/severity:\s*(critical|warning)/', $block))
            ->toBe(1, "{$name} has no usable severity");
    }
})->with(['eruofood-financial', 'eruofood-security']);

it('keeps the un-tunable alerts at critical', function (string $alert): void {
    // These four must never be softened to reduce noise. An unknown transfer
    // may already have paid a merchant; a ledger that does not balance means
    // the book is wrong; a duplicate payout attempt means a constraint is gone.
    $block = alertBlocks('eruofood-financial')[$alert] ?? null;

    expect($block)->not->toBeNull()
        ->and($block)->toMatch('/severity:\s*critical/');
})->with(['SettlementUnknownOutcome', 'LedgerImbalance', 'PayableDrift', 'DuplicatePayoutAttempted']);

it('fires the unknown-outcome alert immediately rather than after a delay', function (): void {
    // `for: 0m`. Waiting even five minutes to mention that a transfer's outcome
    // is unknown is five minutes in which a retry could pay somebody twice.
    expect(alertBlocks('eruofood-financial')['SettlementUnknownOutcome'])->toMatch('/for:\s*0m/');
});

it('points the unknown-outcome responder at a runbook that exists', function (): void {
    $block = alertBlocks('eruofood-financial')['SettlementUnknownOutcome'];

    expect($block)->toContain('runbook:');

    preg_match('#runbook:\s*"?(docs/[A-Za-z0-9_./-]+?)(?:\#|"|\s|$)#', $block, $matches);

    expect($matches[1] ?? '')->not->toBe('')
        ->and(file_exists(dirname(base_path(), 2).'/'.$matches[1]))
        ->toBeTrue("runbook {$matches[1]} does not exist");
});
