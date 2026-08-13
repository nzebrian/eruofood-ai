<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Shared Kernel configuration
|------------------------------------------------------------------------------
| Tunables for the cross-cutting primitives every bounded context relies on:
| the transaction boundary and the idempotency store. Both are deliberately
| conservative — these settings govern how the platform behaves when two
| requests race or when a client retries a money-moving call.
*/

return [
    'transaction' => [
        /*
        | How many times a use case is retried when the database aborts it with a
        | deadlock or serialisation failure. Those are transient — the transaction
        | rolled back cleanly, so replaying the closure is safe. Business failures
        | are never retried; they surface on the first attempt.
        */
        'attempts' => (int) env('DB_TRANSACTION_ATTEMPTS', 3),
    ],

    'idempotency' => [
        /*
        | How long a completed idempotency key keeps replaying its stored
        | response, in seconds. It also bounds how long a key abandoned by a
        | crashed request blocks a retry. 24h comfortably covers client retry
        | windows and payment provider callback retries.
        */
        'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),
    ],
];
