<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Console;

use EruoFood\PublicApi\Application\Service\WebhookService;
use Illuminate\Console\Command;

/**
 * Re-attempts webhook deliveries whose exponential-backoff window has elapsed.
 * Run on a schedule (e.g. every minute) so transient endpoint failures recover
 * automatically until the attempt ceiling.
 */
final class DispatchWebhookRetriesCommand extends Command
{
    protected $signature = 'publicapi:dispatch-webhooks {--limit=100 : Maximum deliveries to retry}';

    protected $description = 'Retry due webhook deliveries with exponential backoff.';

    public function handle(WebhookService $webhooks): int
    {
        $count = $webhooks->retryDue((int) $this->option('limit'));
        $this->info(sprintf('Retried %d webhook delivery(ies).', $count));

        return self::SUCCESS;
    }
}
