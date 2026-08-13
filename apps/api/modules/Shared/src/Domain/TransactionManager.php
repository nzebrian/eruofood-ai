<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain;

/**
 * Defines a single atomic boundary around a use case.
 *
 * Application services orchestrate several repositories to complete one business
 * operation (debit a wallet *and* credit another; decrement stock *and* place an
 * order). Without one boundary each repository commits independently, so a
 * failure part-way through leaves the system in a state the domain considers
 * impossible — money destroyed, stock reserved against no order.
 *
 * The port lives in the domain so the application layer can express "all of this
 * or none of it" without importing the framework. The infrastructure adapter
 * supplies the real database transaction.
 *
 * Nesting is safe: an inner {@see atomic()} joins the outer transaction rather
 * than opening a second one, so a service may call another service that also
 * declares a boundary.
 */
interface TransactionManager
{
    /**
     * Run $work inside one transaction, committing on return and rolling back on
     * any throwable. A serialisation/deadlock failure is retried; any other
     * throwable propagates to the caller after the rollback.
     *
     * @template TReturn
     *
     * @param callable():TReturn $work
     * @return TReturn
     */
    public function atomic(callable $work): mixed;
}
