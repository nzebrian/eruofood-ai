# Mobile Retry Queue — transport integration

`apps/mobile/lib/core/resilience/` — the client half of the recovery sequence
the API's `POST /api/v1/reconcile` endpoint was built to serve.

## The question this exists to answer

A customer taps **Place order**, the connection dies, the app restarts. The app
knows two things: it sent a request, and it never heard back. Those two facts
are compatible with the order having succeeded, having failed, and being in
progress right now.

Every recovery strategy that *guesses* is wrong some of the time, and the wrong
guesses are "charged twice" and "told it failed when it did not".

So the client does not guess. It mints an idempotency key **before** sending,
persists the operation, sends, and — if it never hears back — asks the server
what happened using that key.

## What was missing until M30-D

The decision logic (`RetryQueue`, `PendingOperation`) and the persistence
*interface* (`PendingOperationStore`) shipped with the cross-cutting foundation
and were fully tested. Nothing used them. There was no interceptor, no store
implementation, and no mobile request that sent an `Idempotency-Key` header at
all — `git grep -rn "Idempotency" apps/mobile` was empty.

The queue was a mechanism with no subject. M30-D connects it.

## The pieces

| File | Role |
|---|---|
| `retry_eligibility.dart` | Which endpoints may be queued, and what a failure lets the client assume |
| `retry_queue_interceptor.dart` | The Dio interceptor: mint → persist → send → resolve |
| `secure_pending_operation_store.dart` | `PendingOperationStore` on Keychain/Keystore |
| `reconciliation_gateway.dart` | `POST /reconcile`, batched to the server's limit of 50 |
| `retry_queue_processor.dart` | One controlled pass: reconcile, then resend only what is safe |
| `idempotency_key.dart` | 128 bits from `Random.secure()`, hex-encoded |

`retry_queue.dart`, `pending_operation.dart` and `freshness.dart` are unchanged
apart from one additive constant — see *Terminal behaviour*.

## Eligibility is declared, never inferred

The tempting rule is "queue anything that failed with a network error". It is
wrong for the same reason the server refuses to guess: a request the app never
got an answer to may have taken effect. **Whether a resend is safe is a property
of the endpoint, not of the error** — and the server already published that
property, as its idempotency scopes.

So `RetryEligibility.endpoints` names them, and nothing else is ever queued:

| Scope | Request | Money-moving |
|---|---|---|
| `commerce.checkout` | `POST /commerce/checkout` | yes |
| `marketplace.checkout` | `POST /checkout` | yes |
| `payments.initiate` | `POST /payments/payments` | yes |
| `payments.wallet.topup` | `POST /payments/wallet/topup` | yes |

Three scopes in the server's contract — `payments.refund`,
`payments.wallet.transfer`, `dispatch.accept` — are **not** declared, because no
mobile feature calls them. A rule nothing exercises is a rule that drifts out of
truth.

Underneath sits a hard floor: `deniedPathPrefixes` refuses `/auth`, `/oauth` and
`/password` whatever the declarations say. `POST /auth/login` carries a raw
password in its body and a queue is a file on a device.

## Classification

| Failure | Classification | Effect |
|---|---|---|
| timeout, connection error | `transportFailed` | stays queued, attempt counted |
| `408`, `425`, `429` | `transportFailed` | refused *before* the work; stays queued |
| other `4xx` | `serverRefused` | removed — a 422 will be a 422 again |
| `5xx` | `serverIndeterminate` | stays queued; the server may have committed |
| cancelled, bad certificate | `serverRefused` | removed |
| anything unrecognised | `serverIndeterminate` | stays queued |

The conservative direction is *indeterminate*. The expensive mistake is the
other one: classifying an ambiguous failure as a refusal, dropping the record,
and leaving a charge nobody is tracking.

Classification only decides **stay queued or not**. It never decides whether to
resend — that remains the server's answer, via reconciliation.

## The order of operations

```
onRequest   mint key → persist operation → attach Idempotency-Key → send
onResponse  server answered → remove the entry
onError     classify → remove (refused) or record an attempt (ambiguous)
            → rethrow, always
```

Persisting *before* sending is the whole design. A key minted after the fact is
useless for the case worth surviving, because there is no response to carry it.

## Fail closed on money

If the store cannot persist, a money-moving request is **refused rather than
sent**. Sending one with no local record creates exactly the state
reconciliation exists to prevent: a charge the app cannot later ask about. The
customer is told it did not go through, which is the truth.

Every other declared endpoint would proceed without a record and say so through
the diagnostic callback. Today all four declarations are money-moving, so in
practice this always fails closed; the branch exists so that adding a harmless
endpoint later does not silently inherit the strict behaviour.

## Nothing here reports success

The queue has no state meaning "done and it worked". An operation leaves the
queue when the *server* said so.

Three specific consequences:

- A transport failure is rethrown unchanged. The customer sees the failure.
- A storage failure while clearing a **successful** response does not turn that
  success into a failure. The order succeeded; a stale entry is reconciled and
  removed on the next pass, which costs one redundant question to the server.
- A reconciliation the client could not reach resends nothing. *"We could not
  ask"* is not evidence that nothing happened.

## Secrets

Nothing credential-shaped is persisted. `sanitisePayload` strips any key
containing `password`, `token`, `secret`, `authorization`, `pin`, `cvv` or
`card_number`, recursively, and **refuses entirely** for a body that is not a
JSON object — `FormData`, a stream — because replaying an approximation of the
original is worse than never queueing it.

The `Authorization` header is never copied. The interceptor is registered
*after* the token interceptor, so it sees the request as it will be sent and
still stores only method, path and sanitised body. At replay time the token
interceptor supplies whatever is current, so a refreshed session is picked up
for free and a stale bearer token is never replayed.

## Persistence and restart

`SecurePendingOperationStore` writes JSON to the platform keystore under
`eruofood_retry_queue` — the same mechanism `TokenStore` uses. There is one
queue and one store, not a second stack alongside the existing one.

Corrupt data is **quarantined**, not discarded: the raw text moves to
`eruofood_retry_queue_corrupt` and a diagnostic is emitted before the queue
starts empty. An entry that vanishes because a byte flipped is an unresolved
charge nobody can reconcile.

## Replay

`RetryQueueProcessor.process()` runs one pass:

1. `recoverAfterRestart()` — every money-moving operation, and everything with
   an attempt against it — is reconciled first.
2. `settled` / `succeeded` → removed. `in_progress` → left alone, **not** resent:
   the server holds a claim, another attempt would be refused, and the app would
   report a failure for a payment about to succeed.
3. `never_received` **with** `safe_to_resend` → eligible.
4. Eligible entries past their backoff and under the attempt ceiling are resent
   through the production `ApiClient`, carrying the original key.

An operation the server did not answer for is left exactly as it was. Absent is
not settled.

### What "controlled" rules out

- **Concurrent passes.** One flag, set synchronously before the first `await`.
- **Polling.** Nothing loops or schedules itself. `process()` runs once and
  returns.
- **Sending before asking.** See step 1.

### When it runs

On app resume, and on the transition to authenticated. `POST /reconcile`
answers on `(account, scope, key)` and never on the key alone, so there is
nothing to ask before a session exists.

There is no connectivity trigger. This project has no connectivity package and
adding one would be a lockfile change; app resume is the honest substitute. A
device that regains signal while the app is already open and authenticated waits
for the next resume.

## Backoff and terminal behaviour

Backoff is `PendingOperation`'s existing curve — 2s, 4s, 8s … capped at five
minutes — unchanged.

`PendingOperation.maxAttempts` (8) is new, and additive. It bounds the *number*
of tries, which the backoff ceiling does not. Reaching it does **not** delete the
operation: a money-moving entry with exhausted attempts is still an unresolved
claim, and dropping it would be the client deciding an outcome it does not know.
It stops being sent automatically and appears in `RetryQueueRun.exhausted` —
something a person has to resolve.

## Tests

| File | Covers |
|---|---|
| `retry_eligibility_test.dart` | declarations, the auth deny floor, classification, sanitisation |
| `retry_queue_interceptor_test.dart` | enqueue-before-send, removal on success, classification effects, duplicate prevention, secrets, fail-closed |
| `retry_queue_processor_test.dart` | reconcile-first, backoff, exhaustion, single-flight, token refresh |
| `secure_pending_operation_store_test.dart` | round-trip, empty-clears-key, quarantine |
| `commerce_retry_queue_test.dart` | **a real feature end to end** |
| `retry_transport_negative_controls_test.dart` | six negative controls |
| `retry_queue_test.dart` | the pre-existing queue logic, unchanged |

### The negative controls

A validator whose subject is already clean passes for two indistinguishable
reasons: it works, or it checks nothing.

| # | Proves |
|---|---|
| 1 | A client built without the interceptor sends the charge and records nothing — the "tests pass, no feature protected" failure mode, written out |
| 2 | An indiscriminate classifier would queue a `401` on `/auth/login`; ours refuses the path outright |
| 3 | A queue keyed on the endpoint would collapse two genuine orders into one |
| 4 | A processor that resent without asking would send a charge the server is still working on |
| 5 | Every declared endpoint is walked, not one example, so adding a declaration without wiring fails here |
| 6 | **The control on the controls** — the harness can observe a bypass, so the assertions above are not vacuous |

Only the socket is substituted. `ScriptedAdapter` replaces Dio's
`httpClientAdapter`, so the production `ApiClient`, interceptor chain, repository
and data source are all the real ones. Substituting `ApiClient` — or a fake
`Dio` — would let every test pass while the real transport bypassed the queue.

## What this does not do

- **It does not make the app offline-capable.** Four endpoints are declared.
  Everything else fails the way it always did.
- **It does not retry reads.** A `GET` has nothing to reconcile.
- **It does not enable any money movement.** It changes when a request is
  *recorded*, never what the server does with it.
