import 'package:dio/dio.dart';
import 'package:eruofood/core/resilience/retry_queue_interceptor.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/transport_harness.dart';

const Map<String, dynamic> _checkout = <String, dynamic>{
  'address_id': 'addr-1',
  'pickup': false,
};

void main() {
  group('enqueue on the way out', () {
    test('a declared endpoint is recorded before it is sent', () async {
      // Order matters: the record has to exist before the request leaves, or a
      // crash mid-flight leaves a charge nobody can reconcile.
      final harness = Harness(adapter: ScriptedAdapter.ok());

      await harness.client.post<dynamic>('/commerce/checkout', data: _checkout);

      expect(harness.adapter.sent, hasLength(1));
      expect(
        harness.adapter.sent.single.headers[RetryQueueInterceptor.headerName],
        'key-1',
      );
    });

    test('an undeclared endpoint is left completely alone', () async {
      final harness = Harness(adapter: ScriptedAdapter.ok());

      await harness.client
          .post<dynamic>('/commerce/wishlist', data: <String, dynamic>{'p': 1});

      expect(harness.queue.operations, isEmpty);
      expect(
        harness.adapter.sent.single.headers
            .containsKey(RetryQueueInterceptor.headerName),
        isFalse,
      );
    });

    test('an auth request is never queued and never gets a key', () async {
      // The hard floor. `/auth/login` carries a raw password in its body.
      final harness = Harness(adapter: ScriptedAdapter.ok());

      await harness.client.post<dynamic>(
        '/auth/login',
        data: <String, dynamic>{'email': 'a@b.test', 'password': 'hunter2'},
      );

      expect(harness.queue.operations, isEmpty);
      expect(harness.store.saved, isEmpty);
      expect(
        harness.adapter.sent.single.headers
            .containsKey(RetryQueueInterceptor.headerName),
        isFalse,
      );
    });
  });

  group('what happens after the answer', () {
    test('a successful request leaves nothing queued', () async {
      final harness = Harness(adapter: ScriptedAdapter.ok());

      await harness.client.post<dynamic>('/commerce/checkout', data: _checkout);

      expect(harness.queue.operations, isEmpty);
      expect(harness.store.saved, isEmpty);
    });

    test('a resolved operation is removed exactly once', () async {
      final harness = Harness(adapter: ScriptedAdapter.ok());

      await harness.client.post<dynamic>('/commerce/checkout', data: _checkout);

      // One save to enqueue, one to remove. A third would mean the response
      // path ran twice, which is how an entry gets removed while a duplicate
      // of it is still in flight.
      expect(harness.store.saveCount, 2);
      expect(harness.queue.operations, isEmpty);
    });

    test('a real transport failure stays queued, with the attempt counted',
        () async {
      final harness = Harness(adapter: ScriptedAdapter.connectionError());

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      final queued = harness.queue.operations.single;
      expect(queued.idempotencyKey, 'key-1');
      expect(queued.scope, 'commerce.checkout');
      expect(queued.isMoneyMoving, isTrue);
      expect(queued.attempts, 1);
      expect(queued.lastAttemptAt, harness.clock);
    });

    test('a 5xx stays queued — the server may have committed', () async {
      final harness = Harness(adapter: ScriptedAdapter.status(500));

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      expect(harness.queue.operations, hasLength(1));
    });

    test('a validation refusal is not queued for retry', () async {
      // A 422 will be a 422 again. Keeping it would build a queue that never
      // drains and retries a request the server has already judged.
      final harness = Harness(adapter: ScriptedAdapter.status(422));

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      expect(harness.queue.operations, isEmpty);
    });

    test('an authorisation failure is not queued for retry', () async {
      final harness = Harness(adapter: ScriptedAdapter.status(401));

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      expect(harness.queue.operations, isEmpty);
    });

    test('a rate limit is treated as never-executed', () async {
      final harness = Harness(adapter: ScriptedAdapter.status(429));

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      expect(harness.queue.operations.single.attempts, 1);
    });
  });

  group('duplicate prevention', () {
    test('two concurrent sends produce two distinct records, not a lost one',
        () async {
      // The read-modify-write inside the queue is why the interceptor is a
      // QueuedInterceptor. Interleaved, the second save would overwrite the
      // first and one of these charges would be sent with no record at all.
      final harness = Harness(adapter: ScriptedAdapter.connectionError());

      Future<void> send() async {
        try {
          await harness.client
              .post<dynamic>('/commerce/checkout', data: _checkout);
        } on DioException {
          // Expected — the harness drops every connection.
        }
      }

      await Future.wait<void>(<Future<void>>[send(), send()]);

      expect(harness.queue.operations, hasLength(2));
      expect(
        harness.queue.operations.map((o) => o.idempotencyKey).toSet(),
        <String>{'key-1', 'key-2'},
      );
    });

    test('a replay reuses the original key rather than minting a second',
        () async {
      final harness = Harness(adapter: ScriptedAdapter.ok());

      await harness.client.raw.post<dynamic>(
        '/commerce/checkout',
        data: _checkout,
        options: Options(extra: <String, dynamic>{
          RetryQueueInterceptor.replayKeyExtra: 'original-key',
        }),
      );

      expect(
        harness.adapter.sent.single.headers[RetryQueueInterceptor.headerName],
        'original-key',
      );
      // No new record: the operation was already queued when it was first sent.
      expect(harness.store.saveCount, 1);
    });
  });

  group('secrets never reach storage', () {
    test('a queued payment body holds no card data or token', () async {
      final harness = Harness(adapter: ScriptedAdapter.connectionError());

      await expectLater(
        harness.client.post<dynamic>('/payments/payments', data: <String, dynamic>{
          'order_id': 'o-1',
          'amount': 250000,
          'card_number': '4111111111111111',
          'cvv': '123',
          'access_token': 'should-never-be-stored',
        }),
        throwsA(isA<DioException>()),
      );

      final payload = harness.queue.operations.single.payload;

      expect(payload, <String, dynamic>{'order_id': 'o-1', 'amount': 250000});
      expect(payload.keys, isNot(contains('card_number')));
      expect(payload.keys, isNot(contains('cvv')));
      expect(payload.keys, isNot(contains('access_token')));
    });

    test('the Authorization header is never persisted', () async {
      final harness = Harness(adapter: ScriptedAdapter.connectionError());

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      // The request that went out carried a bearer token…
      expect(harness.adapter.sent.single.headers['Authorization'],
          'Bearer access-token-v1');

      // …and nothing resembling it reached the stored record.
      final stored = harness.queue.operations.single.toJson().toString();
      expect(stored, isNot(contains('access-token-v1')));
      expect(stored.toLowerCase(), isNot(contains('authorization')));
    });

    test('a body that cannot be reconstructed is sent but not queued',
        () async {
      final harness = Harness(adapter: ScriptedAdapter.connectionError());

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: FormData()),
        throwsA(isA<DioException>()),
      );

      expect(harness.queue.operations, isEmpty);
      expect(harness.diagnostics.join('\n'), contains('could not be stored'));
    });
  });

  group('queue infrastructure failure', () {
    test('a money-moving request is refused rather than sent unrecorded',
        () async {
      final harness = Harness(adapter: ScriptedAdapter.ok())
        ..store.failure = StateError('keystore unavailable');

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(
          isA<DioException>().having(
              (e) => e.error, 'error', isA<RetryQueueUnavailable>()),
        ),
      );

      // Nothing reached the wire. The customer is told it did not go through,
      // which is the truth.
      expect(harness.adapter.sent, isEmpty);
    });

    test('a broken queue never converts a real success into a failure',
        () async {
      // The enqueue lands; storage then breaks before the response can clear
      // the entry. The order genuinely succeeded on the server, so the caller
      // must be told it succeeded. Reporting a failure here would be exactly
      // as dishonest as reporting a success that did not happen.
      final harness = Harness(adapter: ScriptedAdapter.ok())
        ..store.failAfterSaves = 1
        ..store.failure = StateError('keystore unavailable');

      final response =
          await harness.client.post<dynamic>('/commerce/checkout', data: _checkout);

      expect(response.statusCode, 201);
      expect(
        harness.diagnostics.join('\n'),
        contains('could not clear resolved operation'),
      );
    });

    test('a stale entry left by that failure is still reconcilable', () async {
      // It stays on the queue and is resolved as settled on the next pass —
      // a redundant question to the server, never a second order.
      final harness = Harness(adapter: ScriptedAdapter.ok())
        ..store.failAfterSaves = 1
        ..store.failure = StateError('keystore unavailable');

      await harness.client.post<dynamic>('/commerce/checkout', data: _checkout);

      expect(harness.store.saved.single.idempotencyKey, 'key-1');
    });
  });
}
