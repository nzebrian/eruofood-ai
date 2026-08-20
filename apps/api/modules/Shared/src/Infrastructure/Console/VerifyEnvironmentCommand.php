<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Console;

use EruoFood\Shared\Domain\Environment\EnvironmentPolicy;
use EruoFood\Shared\Domain\Environment\EnvironmentSnapshot;
use EruoFood\Shared\Domain\Environment\FindingSeverity;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagRegistry;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Answer, from inside a running deployment, whether it is configured safely.
 *
 * Run it as a deploy gate — it exits non-zero on any error-severity finding — and
 * run it by hand when you want to know what a box actually believes about
 * itself. The two questions it exists to answer are the ones no config file
 * answers on its own: *which payment provider will this environment really use*,
 * and *which money-moving switches are on right now*.
 *
 * ## Why it prints even when it passes
 *
 * The provider and the settlement flags are printed on every run, pass or fail.
 * A gate that is silent when healthy teaches operators that silence means
 * "checked"; here silence would also be what a gate that never ran looks like.
 *
 * ## What it does not do
 *
 * It changes nothing. It cannot disable a flag, rotate a credential or pin a
 * provider. A validator that repairs what it finds hides the fact that a
 * deployment shipped broken.
 */
final class VerifyEnvironmentCommand extends Command
{
    protected $signature = 'ops:verify-environment {--json : Emit findings as JSON for a deploy pipeline}';

    protected $description = 'Verify environment separation and financial safety configuration; exits non-zero on any error.';

    public function handle(
        Config $config,
        EnvironmentPolicy $policy,
        FlagRegistry $flags,
        FlagEvaluator $evaluator,
        ScheduleRegistry $schedule,
    ): int {
        $snapshot = $this->snapshot($config, $flags, $evaluator, $schedule);
        $findings = $policy->evaluate($snapshot);

        $errors = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->severity === FindingSeverity::Error,
        ));
        $warnings = array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->severity === FindingSeverity::Warning,
        ));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'environment' => $snapshot->appEnvRaw,
                'payments_provider' => $snapshot->paymentsProvider,
                'payments_provider_pinned' => $snapshot->paymentsProviderPinned,
                'errors' => array_map($this->toArray(...), $errors),
                'warnings' => array_map($this->toArray(...), $warnings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(sprintf('Environment: %s', (string) $snapshot->appEnvRaw));
        $this->line(sprintf(
            '  payment provider   %s (%s)',
            (string) $snapshot->paymentsProvider,
            $snapshot->paymentsProviderPinned ? 'pinned' : 'from config default',
        ));

        foreach ($snapshot->flags as $key => $enabled) {
            if (str_starts_with($key, 'settlement.') || $key === 'dispatch.engine') {
                $this->line(sprintf('  %-26s %s', $key, $enabled ? 'ON' : 'off'));
            }
        }

        $this->newLine();

        foreach ([['error', $errors], ['warning', $warnings]] as [$label, $group]) {
            foreach ($group as $finding) {
                $this->line(sprintf(
                    '%s  [%s] %s',
                    $label === 'error' ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>',
                    $finding->code,
                    $finding->summary,
                ));
                $this->line(sprintf('        %s', $finding->remedy));
            }
        }

        if ($errors === []) {
            $this->newLine();
            $this->components->info(sprintf(
                'No unsafe configuration found (%d warning%s).',
                count($warnings),
                count($warnings) === 1 ? '' : 's',
            ));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error(sprintf('%d unsafe configuration finding(s).', count($errors)));

        return self::FAILURE;
    }

    private function snapshot(
        Config $config,
        FlagRegistry $flags,
        FlagEvaluator $evaluator,
        ScheduleRegistry $schedule,
    ): EnvironmentSnapshot {
        $connection = (string) $config->get('database.default');

        $resolved = [];
        foreach ($flags->all() as $flag) {
            $resolved[$flag->key] = $evaluator->isEnabled($flag->key);
        }

        $tasks = [];
        foreach ($schedule->all() as $task) {
            $tasks[$task->name] = $task->enabled;
        }

        return new EnvironmentSnapshot(
            appEnvRaw: (string) $config->get('app.env'),
            appDebug: (bool) $config->get('app.debug'),
            appKey: $this->asString($config->get('app.key')),
            appName: $this->asString($config->get('app.name')),
            logLevel: $this->asString(
                $config->get('shared.environment.log_level') ?? $config->get('logging.channels.single.level'),
            ),
            dbConnection: $connection,
            dbDatabase: $this->asString($config->get("database.connections.{$connection}.database")),
            dbPassword: $this->asString($config->get("database.connections.{$connection}.password")),
            dbSslMode: $this->asString(
                $config->get("database.connections.{$connection}.sslmode") ?? $config->get('shared.environment.db_sslmode'),
            ),
            redisHost: $this->asString($config->get('database.redis.default.host')),
            redisPassword: $this->asString($config->get('database.redis.default.password')),
            redisScheme: $this->asString(
                $config->get('database.redis.default.scheme') ?? $config->get('shared.environment.redis_scheme'),
            ),
            redisPrefix: $this->asString($config->get('database.redis.options.prefix')),
            queueConnection: $this->asString($config->get('queue.default')),
            sessionSecureCookie: (bool) $config->get('session.secure'),
            filesystemDisk: $this->asString($config->get('filesystems.default')),
            paymentsProvider: $this->asString($config->get('payments.default')),
            // Config rather than env(): env() is null once config is cached,
            // which would report every production deployment as unpinned.
            paymentsProviderPinned: (bool) $config->get('payments.provider_pinned'),
            flags: $resolved,
            scheduledTasks: $tasks,
        );
    }

    private function asString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** @return array{code: string, summary: string, remedy: string} */
    private function toArray(\EruoFood\Shared\Domain\Environment\EnvironmentFinding $finding): array
    {
        return ['code' => $finding->code, 'summary' => $finding->summary, 'remedy' => $finding->remedy];
    }
}
