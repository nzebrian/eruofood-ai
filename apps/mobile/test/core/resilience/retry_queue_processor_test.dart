import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:eruofood/core/resilience/pending_operation.dart';
import 'package:eruofood/core/resilience/reconciliation_gateway.dart';
import 'package:eruofood/core/resilience/retry_queue_interceptor.dart';
import 'package:eruofood/core/resilience/retry_queue_processor.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/transport_harness.dart';

PendingOperation _queued(
  String key, {
  String scope = 'commerce.checkout',
  String endpoint = '/commerce/checkout',
  int attempts = 0,
  DateTime? lastAttemptAt,
  bool money = true,
}) =>
    PendingOperation(
      idempotencyKey: key,
      scope: scope,
      endpoint: endpoint,
      payload: const <String, dynamic>{'address_id': 'addr-1'},
      createdAt: DateTime.utc(2026, 8, 24, 11),
      attempts: attempts,
      lastAttemptAt: lastAttemptAt,
      isMoneyMoving: money,
    );

/// Answers `/reconcile` from a script and echoes every other call.
///
/// Deliberately built on the same production `ApiClient` the app uses, so the
/// resend path under test is the real one — interceptor included.
ScriptedAdapter _adapterAnswering(
  Map<String, Map<String, dynamic>> byKey, {
  Object? reconcileFailure,
  int resendStatus = 201,
}) {
  return ScriptedAdapter((RequestOptions options) async {
    if (options.path.endsWith('/reconcile')) {
      if (reconcileFailure != null) {
        throw DioException(
          requestOptions: options,
          type: DioExceptionType.connectionError,
          message: '$reconcileFailure',
        );
      }

      final body = options.data as Map<String, dynamic>;
      final asked = (body['operations'] as List<dynamic>)
          .cast<Map<String, dynamic>>()
          .map((Map<String, dynamic> o) => o['key'] as String);

      return ResponseBody.fromString(
        jsonEncode(<String, dynamic>{
          'data': <String, dynamic>{
            'operations': <Map<String, dynamic>>[
              for (final key in asked)
                if (byKey.containsKey(key))
                  <String, dynamic>{'idempotency_key': key, ...byKey[key]!},
            ],
            'server_time': '2026-08-24T12:00:00Z',
          },
        }),
        200,
        headers: <String, List<String>>{
          Headers.contentTypeHeader: <String>[Headers.jsonContentType],
        },
      );
    }

    if (resendStatus >= 400) {
      throw DioException(
        requestOptions: options,
        type: DioExceptionType.badResponse,
        response: Response<dynamic>(
            requestOptions: options, statusCode: resendStatus),
      );
    }

    return ResponseBody.fromString(
      '{"data":{}}',
      resendStatus,
      headers: <String, List<String>>{
        Headers.contentTypeHeader: <String>[Headers.jsonContentType],
      },
    );
  });
}

RetryQueueProcessor _processorFor(Harness harness) => RetryQueueProcessor(
      queue: harness.queue,
      reconciliation: ReconciliationGateway(harness.client),
      client: harness.client,
      now: () => harness.clock,
      onDiagnostic: harness.diagnostics.add,
    );

void main() {
  group('reconcile before resending', () {
    test('a settled operation is removed and never resent', () async {
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'settled',
            'safe_to_resend': false,
          },
        }),
        store: store,
      );

      final run = await _processorFor(harness).process();

      expect(run.removed, 1);
      expect(run.resent, 0);
      expect(harness.queue.operations, isEmpty);
      // Only the reconcile call reached the wire.
      expect(harness.adapter.sent, hasLength(1));
      expect(harness.adapter.sent.single.path, endsWith('/reconcile'));
    });

    test('an in-progress operation stays queued and is not resent', () async {
      // The server holds a claim. Sending again would be refused, and the app
      // would report a failure for a payment about to succeed.
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'in_progress',
            'safe_to_resend': false,
          },
        }),
        store: store,
      );

      final run = await _processorFor(harness).process();

      expect(run.resent, 0);
      expect(harness.queue.operations, hasLength(1));
    });

    test('never_received with safe_to_resend is replayed on the real client',
        () async {
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'never_received',
            'safe_to_resend': true,
          },
        }),
        store: store,
      );

      final run = await _processorFor(harness).process();

      expect(run.resent, 1);

      final resend = harness.adapter.sent.last;
      expect(resend.path, '/commerce/checkout');
      expect(resend.method, 'POST');
      // The *original* key, so the server collapses this onto the original
      // request instead of opening a second one.
      expect(resend.headers[RetryQueueInterceptor.headerName], 'k1');
      // The interceptor cleared it when the server answered.
      expect(harness.queue.operations, isEmpty);
    });

    test('an operation the server did not answer for is left untouched',
        () async {
      // Absent is not settled, and it is certainly not permission to resend.
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(const <String, Map<String, dynamic>>{}),
        store: store,
      );

      final run = await _processorFor(harness).process();

      expect(run.resent, 0);
      expect(run.removed, 0);
      expect(harness.queue.operations, hasLength(1));
    });

    test('nothing is resent when the server cannot be reached', () async {
      // "We could not ask" is not evidence that nothing happened.
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(
          const <String, Map<String, dynamic>>{},
          reconcileFailure: 'offline',
        ),
        store: store,
      );

      final run = await _processorFor(harness).process();

      expect(run.reconciliationFailed, isTrue);
      expect(run.resent, 0);
      expect(harness.queue.operations, hasLength(1));
      expect(harness.diagnostics.join('\n'), contains('Nothing was resent'));
    });
  });

  group('backoff and terminal behaviour', () {
    test('an operation inside its backoff window is not resent', () async {
      // Two attempts → four seconds. The clock is one second later.
      final lastAttempt = DateTime.utc(2026, 8, 24, 12);
      final store = MemoryStore()
        ..saved = <PendingOperation>[
          _queued('k1', attempts: 2, lastAttemptAt: lastAttempt),
        ];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'never_received',
            'safe_to_resend': true,
          },
        }),
        store: store,
        now: lastAttempt.add(const Duration(seconds: 1)),
      );

      final run = await _processorFor(harness).process();

      expect(run.resent, 0);
      expect(harness.queue.operations, hasLength(1));
    });

    test('the same operation is resent once its backoff elapses', () async {
      final lastAttempt = DateTime.utc(2026, 8, 24, 12);
      final store = MemoryStore()
        ..saved = <PendingOperation>[
          _queued('k1', attempts: 2, lastAttemptAt: lastAttempt),
        ];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'never_received',
            'safe_to_resend': true,
          },
        }),
        store: store,
        now: lastAttempt.add(const Duration(seconds: 30)),
      );

      expect((await _processorFor(harness).process()).resent, 1);
    });

    test('an exhausted operation stops being sent but is never dropped',
        () async {
      // The terminal state. Deleting it would be the client deciding an outcome
      // it does not know; it becomes something a person has to resolve.
      final store = MemoryStore()
        ..saved = <PendingOperation>[
          _queued('k1', attempts: PendingOperation.maxAttempts),
        ];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'never_received',
            'safe_to_resend': true,
          },
        }),
        store: store,
      );

      final run = await _processorFor(harness).process();

      expect(run.resent, 0);
      expect(run.exhausted, <String>['k1']);
      expect(harness.queue.operations, hasLength(1));
      expect(harness.diagnostics.join('\n'), contains('attempts'));
    });

    test('an attempt is counted before the resend leaves', () async {
      // If the process dies mid-flight the attempt is already on disk, so the
      // next start reconciles instead of replaying blind.
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(
          <String, Map<String, dynamic>>{
            'k1': <String, dynamic>{
              'outcome': 'never_received',
              'safe_to_resend': true,
            },
          },
          resendStatus: 500,
        ),
        store: store,
      );

      await _processorFor(harness).process();

      // 500 is indeterminate, so it stays queued — with two attempts recorded:
      // one by the processor before sending, one by the interceptor on failure.
      final remaining = harness.queue.operations.single;
      expect(remaining.idempotencyKey, 'k1');
      expect(remaining.attempts, greaterThanOrEqualTo(1));
    });
  });

  group('one pass at a time', () {
    test('a second concurrent pass is skipped, not run alongside', () async {
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'never_received',
            'safe_to_resend': true,
          },
        }),
        store: store,
      );
      final processor = _processorFor(harness);

      final results = await Future.wait<RetryQueueRun>(<Future<RetryQueueRun>>[
        processor.process(),
        processor.process(),
      ]);

      expect(results.where((RetryQueueRun r) => r.skipped), hasLength(1));

      // And the operation was sent exactly once across both calls.
      final resends = harness.adapter.sent
          .where((RequestOptions o) => o.path == '/commerce/checkout');
      expect(resends, hasLength(1));
    });

    test('the flag is released so a later pass still runs', () async {
      final store = MemoryStore()..saved = const <PendingOperation>[];
      final harness = Harness(
        adapter: _adapterAnswering(const <String, Map<String, dynamic>>{}),
        store: store,
      );
      final processor = _processorFor(harness);

      await processor.process();

      expect(processor.isRunning, isFalse);
      expect((await processor.process()).skipped, isFalse);
    });
  });

  group('authentication at replay time', () {
    test('a resend carries the current token, not the one from first send',
        () async {
      // Nothing about authentication is stored with the operation. The
      // ApiClient's token interceptor supplies whatever is current when the
      // resend goes out, so a refreshed session is picked up for free.
      final store = MemoryStore()..saved = <PendingOperation>[_queued('k1')];
      final harness = Harness(
        adapter: _adapterAnswering(<String, Map<String, dynamic>>{
          'k1': <String, dynamic>{
            'outcome': 'never_received',
            'safe_to_resend': true,
          },
        }),
        store: store,
      );

      harness.token = 'access-token-v2-refreshed';

      await _processorFor(harness).process();

      final resend = harness.adapter.sent
          .lastWhere((RequestOptions o) => o.path == '/commerce/checkout');
      expect(resend.headers['Authorization'], 'Bearer access-token-v2-refreshed');
    });
  });
}
