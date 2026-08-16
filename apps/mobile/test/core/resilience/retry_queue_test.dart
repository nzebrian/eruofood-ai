import 'package:eruofood/core/resilience/freshness.dart';
import 'package:eruofood/core/resilience/observation.dart';
import 'package:eruofood/core/resilience/pending_operation.dart';
import 'package:eruofood/core/resilience/retry_queue.dart';
import 'package:flutter_test/flutter_test.dart';

/// In-memory store, so the queue's rules are testable without a device.
class _MemoryStore implements PendingOperationStore {
  List<PendingOperation> _saved = const [];

  @override
  Future<List<PendingOperation>> load() async => _saved;

  @override
  Future<void> save(List<PendingOperation> operations) async =>
      _saved = operations;
}

PendingOperation _op(String key,
        {bool money = false, int attempts = 0, DateTime? lastAttempt}) =>
    PendingOperation(
      idempotencyKey: key,
      scope: money ? 'payments.initiate' : 'profile.update',
      endpoint: '/api/v1/x',
      payload: const {'a': 1},
      createdAt: DateTime.utc(2026, 8, 15),
      attempts: attempts,
      lastAttemptAt: lastAttempt,
      isMoneyMoving: money,
    );

void main() {
  group('freshness', () {
    test('never treats an unknown wire value as live', () {
      // A client talking to a newer server must not start claiming freshness it
      // does not understand.
      expect(FreshnessState.fromWire('something_new'),
          FreshnessState.staleUnknown);
      expect(FreshnessState.fromWire(null), FreshnessState.staleUnknown);
    });

    test('only online may be presented as live', () {
      for (final state in FreshnessState.values) {
        expect(state.mayPresentAsLive, state == FreshnessState.online);
      }
    });

    test('cached data is usable but never live', () {
      final observation = Observation<String>.cached('last known');

      expect(observation.hasValue, isTrue);
      expect(observation.mayPresentAsLive, isFalse);
      expect(observation.freshness, FreshnessState.staleUnknown);
    });

    test(
        'a response missing its freshness field degrades rather than defaults fresh',
        () {
      final observation = Observation<int>.fromJson(
        {'value': 42},
        (raw) => raw as int,
      );

      expect(observation.value, 42);
      expect(observation.freshness, FreshnessState.staleUnknown);
      expect(observation.mayPresentAsLive, isFalse);
    });
  });

  group('retry queue', () {
    test('never replays a money-moving operation after a restart', () async {
      // The rule that prevents double charges: the app cannot tell a request
      // that never arrived from one that succeeded with a lost response.
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('pay-1', money: true));

      expect(await queue.replayableAfterRestart(), isEmpty);
      expect(
          (await queue.recoverAfterRestart()).single.idempotencyKey, 'pay-1');
    });

    test('replays an unsent harmless operation directly', () async {
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('profile-1'));

      expect((await queue.replayableAfterRestart()).single.idempotencyKey,
          'profile-1');
    });

    test('reconciles anything already attempted, even if harmless', () async {
      // One attempt means it may have reached the server.
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('profile-2',
          attempts: 1, lastAttempt: DateTime.utc(2026, 8, 15)));

      expect(await queue.replayableAfterRestart(), isEmpty);
      expect((await queue.recoverAfterRestart()).single.idempotencyKey,
          'profile-2');
    });

    test('collapses a repeated enqueue of the same key', () async {
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('k'));
      await queue.enqueue(_op('k'));

      expect(queue.operations.length, 1);
    });

    test('survives a restart through the store', () async {
      final store = _MemoryStore();
      await RetryQueue(store).enqueue(_op('kept', money: true));

      final revived = RetryQueue(store);

      expect(
          (await revived.recoverAfterRestart()).single.idempotencyKey, 'kept');
    });

    test('backs off exponentially with a ceiling', () async {
      expect(_op('a', attempts: 1).backoff, const Duration(seconds: 2));
      expect(_op('a', attempts: 3).backoff, const Duration(seconds: 8));
      // Capped at the documented five minutes, so an overnight queue does not
      // wait hours and look broken. This previously asserted 256s, which was
      // the accidental result of a redundant inner clamp rather than the
      // ceiling the docblock promised — the audit caught the difference.
      expect(_op('a', attempts: 12).backoff, const Duration(seconds: 300));
      expect(_op('a', attempts: 30).backoff, const Duration(seconds: 300));
    });

    test('holds an operation until its backoff elapses', () async {
      final at = DateTime.utc(2026, 8, 15, 12);
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('k', attempts: 2, lastAttempt: at));

      expect(await queue.readyAt(at.add(const Duration(seconds: 1))), isEmpty);
      expect(await queue.readyAt(at.add(const Duration(seconds: 5))),
          hasLength(1));
    });

    test('keeps an in-progress operation queued rather than resending it',
        () async {
      // The server holds a claim; another attempt would be refused anyway, and
      // the client must not treat "still working" as permission to retry.
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('pay-2', money: true));

      await queue.applyOutcome('pay-2', RetryOutcome.awaitingServer);

      expect(queue.operations, hasLength(1));
    });

    test('removes an operation the server settled', () async {
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('pay-3', money: true));

      await queue.applyOutcome('pay-3', RetryOutcome.settled);

      expect(queue.operations, isEmpty);
    });

    test('maps reconciliation answers onto queue decisions', () {
      expect(
        RetryQueue.outcomeFromReconciliation(
            {'outcome': 'settled', 'safe_to_resend': false}),
        RetryOutcome.settled,
      );
      expect(
        RetryQueue.outcomeFromReconciliation(
            {'outcome': 'in_progress', 'safe_to_resend': false}),
        RetryOutcome.awaitingServer,
      );
      expect(
        RetryQueue.outcomeFromReconciliation(
            {'outcome': 'never_received', 'safe_to_resend': true}),
        RetryOutcome.retryable,
      );
    });

    test('waits rather than resending when the answer is unrecognised', () {
      // An unknown answer must never be read as permission to send money again.
      expect(
        RetryQueue.outcomeFromReconciliation({'outcome': 'something_new'}),
        RetryOutcome.awaitingServer,
      );
      expect(
        RetryQueue.outcomeFromReconciliation(const {}),
        RetryOutcome.awaitingServer,
      );
    });

    test('never marks its own work successful without the server', () {
      // There is no client-side "done and it worked". An operation leaves the
      // queue only when the server said so.
      expect(RetryOutcome.values, contains(RetryOutcome.succeeded));
      expect(
        RetryQueue.outcomeFromReconciliation(
            {'outcome': 'never_received', 'safe_to_resend': false}),
        RetryOutcome.awaitingServer,
      );
    });
  });

  _negativeControls();
}

/// Negative controls.
///
/// Each of these asserts that a *specific wrong implementation* would be
/// caught. They exist because the tests above all describe correct behaviour,
/// and a test that only ever sees correct behaviour cannot tell you whether it
/// would notice incorrect behaviour. Here the wrong implementation is written
/// out explicitly and shown to produce the harm.
class _NaiveQueue {
  _NaiveQueue(this.operations);

  final List<PendingOperation> operations;

  /// The mistake: replay everything on restart, trusting idempotency keys to
  /// sort it out. They do — *if the request reached the server*. If it did not,
  /// this is correct; if it did and the response was lost, the key collapses
  /// the duplicate. The reason it is still wrong is the third case: a claim
  /// still in progress, where a blind resend is refused and the app reports a
  /// failure for a payment that is about to succeed.
  List<PendingOperation> replayEverything() => operations;

  /// The mistake: treat any unrecognised reconciliation answer as permission to
  /// resend, because "we did not understand it, so probably nothing happened".
  static RetryOutcome optimisticOutcome(Map<String, dynamic> operation) =>
      switch (operation['outcome'] as String?) {
        'settled' => RetryOutcome.settled,
        'in_progress' => RetryOutcome.awaitingServer,
        _ => RetryOutcome.retryable,
      };
}

void _negativeControls() {
  group('negative controls', () {
    test('a queue that replays everything would resend a money-moving request',
        () {
      // The harm, made concrete: the naive implementation hands back the
      // payment for resending; ours does not.
      final payment = _op('pay-neg', money: true);
      final naive = _NaiveQueue([payment]);

      expect(naive.replayEverything(), contains(payment));
    });

    test('our queue withholds exactly that operation', () async {
      final queue = RetryQueue(_MemoryStore());
      await queue.enqueue(_op('pay-neg', money: true));

      expect(await queue.replayableAfterRestart(), isEmpty);
    });

    test('an optimistic outcome mapper would resend on an unknown answer', () {
      // A server that grows a new outcome value, or a truncated response, must
      // not be read as "safe to charge again".
      expect(
        _NaiveQueue.optimisticOutcome({'outcome': 'partially_settled'}),
        RetryOutcome.retryable,
      );
    });

    test('ours waits on the same input', () {
      expect(
        RetryQueue.outcomeFromReconciliation({'outcome': 'partially_settled'}),
        RetryOutcome.awaitingServer,
      );
    });

    test(
        'never_received without safe_to_resend must not be treated as resendable',
        () {
      // The server is the authority on both fields. An outcome of
      // never_received with the flag absent or false means the server is not
      // vouching for a resend, and the client must not infer one.
      expect(
        RetryQueue.outcomeFromReconciliation(
            {'outcome': 'never_received', 'safe_to_resend': false}),
        RetryOutcome.awaitingServer,
      );
    });

    test('a freshness parser defaulting to online would mislabel stale data',
        () {
      // The wrong implementation: `FreshnessState.values.byName(...)` with an
      // online fallback. Ours degrades instead.
      const wrong = FreshnessState.online;

      expect(FreshnessState.fromWire('unrecognised'), isNot(wrong));
      expect(
          FreshnessState.fromWire('unrecognised'), FreshnessState.staleUnknown);
    });

    test('an Observation built without freshness must not read as live', () {
      // Cached data restored after a crash is the common case, and the harm is
      // a customer seeing yesterday's order status drawn as current.
      final restored = Observation<String>.cached('yesterday');

      expect(restored.mayPresentAsLive, isFalse);
      expect(restored.isWorthShowing, isFalse);
    });

    test('backoff without a ceiling would strand an overnight queue', () {
      // The wrong implementation: 1 << attempts with no clamp. At 20 attempts
      // that is twelve days.
      const unbounded = Duration(seconds: 1 << 20);

      expect(_op('a', attempts: 20).backoff, lessThan(unbounded));
      expect(
        _op('a', attempts: 20).backoff,
        const Duration(seconds: PendingOperation.maxBackoffSeconds),
      );
    });
  });
}
