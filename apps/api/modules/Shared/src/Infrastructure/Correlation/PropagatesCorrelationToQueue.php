<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Correlation;

use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\Correlation\CorrelationId;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Log;

/**
 * Carries the correlation id across the queue boundary.
 *
 * ## The boundary that loses it
 *
 * A request enqueues a job and returns. The job runs seconds or hours later, in
 * a different process, with none of the request's context — so the notification
 * it sends, the provider call it makes and the row it writes all look
 * unrelated to the order that caused them. That gap is where "why did this
 * customer get two messages?" becomes unanswerable.
 *
 * Laravel's `Queue::createPayloadUsing` hook adds fields to every job payload
 * regardless of how it was dispatched, so this works for jobs the platform has
 * not been changed to know about — including framework and package jobs.
 *
 * ## Clearing matters as much as setting
 *
 * A queue worker is a long-lived process running one job after another. Without
 * the clear after each job, job #2 inherits job #1's correlation id and asserts
 * a causal link that never existed — worse than no id, because it is believed.
 */
final readonly class PropagatesCorrelationToQueue
{
    private const PAYLOAD_KEY = 'eruofood_correlation';

    public static function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(static function (): array {
            $correlation = CorrelationContext::current();

            return [
                self::PAYLOAD_KEY => [
                    'internal' => $correlation->internal,
                    'external' => $correlation->external,
                ],
            ];
        });

        $events->listen(JobProcessing::class, static function (JobProcessing $event): void {
            /** @var array<string, mixed> $payload */
            $payload = $event->job->payload();

            // Deliberately left as `mixed` rather than annotated into the shape
            // we hope for. This payload may have been serialised by an older
            // deploy, or by something that is not this platform at all, so the
            // checks below have to be real runtime checks — a docblock
            // asserting the shape would only stop the analyser from noticing
            // that nothing verifies it.
            $carried = $payload[self::PAYLOAD_KEY] ?? null;

            $internal = is_array($carried) ? ($carried['internal'] ?? null) : null;
            $external = is_array($carried) ? ($carried['external'] ?? null) : null;

            $correlation = is_string($internal) && $internal !== ''
                ? CorrelationId::restore($internal, is_string($external) ? $external : null)
                // A job enqueued before this shipped, or by something outside
                // the platform. It still gets an id so its log lines group.
                : CorrelationId::generate();

            CorrelationContext::set($correlation);
            Log::withContext($correlation->toLogContext());
        });

        $events->listen(JobProcessed::class, static function (): void {
            self::release();
        });

        // A failed job must clear too, or the failure's id leaks into whatever
        // the worker picks up next.
        $events->listen(JobExceptionOccurred::class, static function (): void {
            self::release();
        });
    }

    private static function release(): void
    {
        CorrelationContext::clear();
        Log::withoutContext();
    }
}
