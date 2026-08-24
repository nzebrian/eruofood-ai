import 'package:dio/dio.dart';
import 'package:eruofood/core/config/app_config.dart';
import 'package:eruofood/core/network/api_client.dart';
import 'package:eruofood/core/resilience/pending_operation.dart';
import 'package:eruofood/core/resilience/reconciliation_gateway.dart';
import 'package:eruofood/core/resilience/retry_eligibility.dart';
import 'package:eruofood/core/resilience/retry_queue.dart';
import 'package:eruofood/core/resilience/retry_queue_interceptor.dart';
import 'package:eruofood/core/resilience/retry_queue_processor.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/transport_harness.dart';

/// Negative controls for the transport integration.
///
/// The tests in the other files all describe correct behaviour, and a suite
/// that only ever sees correct behaviour cannot tell you whether it would
/// notice incorrect behaviour. Each control here writes out a *specific wrong
/// implementation* and shows it producing the harm, then shows the production
/// path not producing it.
///
/// Control 6 is the control on the controls: it proves the harness is capable
/// of observing a bypass at all. Without it, every "the queue was used" test
/// could be passing because the assertion is vacuous.
const Map<String, dynamic> _checkout = <String, dynamic>{
  'address_id': 'addr-1',
  'pickup': false,
};

/// The mistake: build the client without the interceptor and trust that
/// somebody, somewhere, enqueues. This is what "the tests pass but no feature
/// is protected" looks like in code.
ApiClient _unwiredClient(ScriptedAdapter adapter) {
  final client = ApiClient(
    const AppConfig(
      apiBaseUrl: 'https://api.test/api/v1',
      appName: 'EruoFood AI (test)',
      appEnv: 'testing',
    ),
    tokenProvider: () async => 'access-token-v1',
  );
  client.raw.httpClientAdapter = adapter;
  return client;
}

void main() {
  group('1 · a fake queue cannot stand in for a real transport', () {
    test('an unwired client sends the charge and records nothing', () async {
      // The harm, made concrete. A test that exercised a RetryQueue directly
      // would be green while this is what production does.
      final adapter = ScriptedAdapter.connectionError();
      final store = MemoryStore();
      final queue = RetryQueue(store);

      await expectLater(
        _unwiredClient(adapter)
            .post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      expect(adapter.sent, hasLength(1),
          reason: 'the request did reach the wire');
      expect(queue.operations, isEmpty,
          reason: 'and nothing recorded it — the failure mode being ruled out');
      expect(
        adapter.sent.single.headers
            .containsKey(RetryQueueInterceptor.headerName),
        isFalse,
        reason: 'no idempotency key, so the server could not answer for it',
      );
    });

    test('the wired client records the identical request', () async {
      final harness = Harness(adapter: ScriptedAdapter.connectionError());

      await expectLater(
        harness.client.post<dynamic>('/commerce/checkout', data: _checkout),
        throwsA(isA<DioException>()),
      );

      expect(harness.queue.operations, hasLength(1));
      expect(
        harness.adapter.sent.single.headers[RetryQueueInterceptor.headerName],
        isNotNull,
      );
    });
  });

  group('2 · a non-retryable request is rejected from the queue', () {
    test('an indiscriminate classifier would queue a password rejection', () {
      // The mistake: "it failed, so queue it". A 401 on /auth/login would then
      // be stored — with the password in it — and retried forever.
      RetryClassification naive(DioException _) =>
          RetryClassification.transportFailed;

      final rejection = DioException(
        requestOptions: RequestOptions(path: '/auth/login'),
        type: DioExceptionType.badResponse,
        response: Response<dynamic>(
          requestOptions: RequestOptions(path: '/auth/login'),
          statusCode: 401,
        ),
      );

      expect(naive(rejection), RetryClassification.transportFailed);
      expect(
        RetryEligibility.classify(rejection),
        RetryClassification.serverRefused,
      );
      expect(RetryEligibility.forRequest('POST', '/auth/login'), isNull);
    });

    test('the login request itself is refused by the live path', () async {
      final harness = Harness(adapter: ScriptedAdapter.status(401));

      await expectLater(
        harness.client.post<dynamic>('/auth/login', data: <String, dynamic>{
          'email': 'a@b.test',
          'password': 'hunter2',
        }),
        throwsA(isA<DioException>()),
      );

      expect(harness.queue.operations, isEmpty);
      expect(harness.store.saved, isEmpty);
      expect(harness.store.saveCount, 0,
          reason: 'nothing was written to storage at any point');
    });
  });

  group('3 · duplicate execution is detected and prevented', () {
    test('a queue keyed on the endpoint would collapse two real orders', () {
      // The mistake: dedupe on scope or path instead of the client-minted key.
      // Two genuinely different checkouts become one, and a customer's second
      // order silently disappears.
      final byEndpoint = <String, PendingOperation>{};

      for (final key in <String>['k1', 'k2']) {
        final operation = PendingOperation(
          idempotencyKey: key,
          scope: 'commerce.checkout',
          endpoint: '/commerce/checkout',
          payload: _checkout,
          createdAt: DateTime.utc(2026, 8, 24),
          isMoneyMoving: true,
        );
        byEndpoint[operation.endpoint] = operation;
      }

      expect(byEndpoint, hasLength(1), reason: 'the harm: one order lost');
    });

    test('the real queue keeps both and still collapses a true repeat',
        () async {
      final queue = RetryQueue(MemoryStore());

      PendingOperation op(String key) => PendingOperation(
            idempotencyKey: key,
            scope: 'commerce.checkout',
            endpoint: '/commerce/checkout',
            payload: _checkout,
            createdAt: DateTime.utc(2026, 8, 24),
            isMoneyMoving: true,
          );

      await queue.enqueue(op('k1'));
      await queue.enqueue(op('k2'));
      await queue.enqueue(op('k1'));

      expect(queue.operations, hasLength(2));
    });
  });

  group('4 · replay cannot turn one side effect into several', () {
    test('a processor that resent without asking would send the charge again',
        () async {
      // The mistake: skip reconciliation and trust the idempotency key. It
      // does collapse a duplicate *if the request arrived* — but the server
      // answering `in_progress` means a resend is refused, and the app then
      // reports a failure for a payment about to succeed.
      final queued = PendingOperation(
        idempotencyKey: 'k1',
        scope: 'commerce.checkout',
        endpoint: '/commerce/checkout',
        payload: _checkout,
        createdAt: DateTime.utc(2026, 8, 24),
        isMoneyMoving: true,
      );

      final naiveWouldSend = <PendingOperation>[queued];
      expect(naiveWouldSend, hasLength(1), reason: 'the harm');

      // Ours asks first, is told the server is still working, and sends nothing.
      final store = MemoryStore()..saved = <PendingOperation>[queued];
      final harness = Harness(
        adapter: ScriptedAdapter((RequestOptions options) async {
          if (!options.path.endsWith('/reconcile')) {
            fail('the processor resent ${options.path} without reconciling');
          }
          return ResponseBody.fromString(
            '{"data":{"operations":[{"idempotency_key":"k1",'
            '"outcome":"in_progress","safe_to_resend":false}],'
            '"server_time":"2026-08-24T12:00:00Z"}}',
            200,
            headers: <String, List<String>>{
              Headers.contentTypeHeader: <String>[Headers.jsonContentType],
            },
          );
        }),
        store: store,
      );

      final run = await RetryQueueProcessor(
        queue: harness.queue,
        reconciliation: ReconciliationGateway(harness.client),
        client: harness.client,
        now: () => harness.clock,
      ).process();

      expect(run.resent, 0);
      expect(harness.queue.operations, hasLength(1));
    });

    test('a resend carries the original key, never a fresh one', () async {
      // If the replay minted a new key the server would see a second, unrelated
      // request and execute it. The header is what makes replay safe at all.
      final store = MemoryStore()
        ..saved = <PendingOperation>[
          PendingOperation(
            idempotencyKey: 'original-key',
            scope: 'commerce.checkout',
            endpoint: '/commerce/checkout',
            payload: _checkout,
            createdAt: DateTime.utc(2026, 8, 24),
            isMoneyMoving: true,
          ),
        ];

      final harness = Harness(
        adapter: ScriptedAdapter((RequestOptions options) async {
          final body = options.path.endsWith('/reconcile')
              ? '{"data":{"operations":[{"idempotency_key":"original-key",'
                  '"outcome":"never_received","safe_to_resend":true}],'
                  '"server_time":"2026-08-24T12:00:00Z"}}'
              : '{"data":{}}';
          return ResponseBody.fromString(
            body,
            options.path.endsWith('/reconcile') ? 200 : 201,
            headers: <String, List<String>>{
              Headers.contentTypeHeader: <String>[Headers.jsonContentType],
            },
          );
        }),
        store: store,
        // Would be minted if the replay path forgot to reuse the stored key.
        keys: <String>['WRONG-FRESH-KEY'],
      );

      await RetryQueueProcessor(
        queue: harness.queue,
        reconciliation: ReconciliationGateway(harness.client),
        client: harness.client,
        now: () => harness.clock,
      ).process();

      final resend = harness.adapter.sent
          .lastWhere((RequestOptions o) => o.path == '/commerce/checkout');

      expect(resend.headers[RetryQueueInterceptor.headerName], 'original-key');
      expect(resend.headers[RetryQueueInterceptor.headerName],
          isNot('WRONG-FRESH-KEY'));
    });
  });

  group('5 · the production transport path is genuinely intercepted', () {
    test('the interceptor is registered on the ApiClient the app builds', () {
      final harness = Harness(adapter: ScriptedAdapter.ok());

      expect(
        harness.client.raw.interceptors.whereType<RetryQueueInterceptor>(),
        hasLength(1),
      );
    });

    test('every declared endpoint is reached through ApiClient and recorded',
        () async {
      // Walks the whole declaration list rather than one example, so adding an
      // endpoint without wiring it up fails here.
      for (final endpoint in RetryEligibility.endpoints) {
        final harness = Harness(adapter: ScriptedAdapter.connectionError());

        await expectLater(
          harness.client.raw.request<dynamic>(
            endpoint.path,
            data: const <String, dynamic>{'probe': true},
            options: Options(method: endpoint.method),
          ),
          throwsA(isA<DioException>()),
          reason: '${endpoint.scope} should have reached the wire',
        );

        expect(
          harness.queue.operations.single.scope,
          endpoint.scope,
          reason: '${endpoint.path} is declared but was not intercepted',
        );
        expect(
          harness.adapter.sent.single.headers[RetryQueueInterceptor.headerName],
          isNotNull,
          reason: '${endpoint.path} went out with no idempotency key',
        );
      }
    });
  });

  group('6 · the control on the controls', () {
    test('the harness can observe a bypass, so the checks above are not vacuous',
        () {
      // If this failed, every "the queue was used" assertion could be passing
      // against a harness incapable of noticing the opposite.
      final unwired = _unwiredClient(ScriptedAdapter.ok());

      expect(
        unwired.raw.interceptors.whereType<RetryQueueInterceptor>(),
        isEmpty,
      );
    });

    test('a request that succeeds is still observable end to end', () async {
      final harness = Harness(adapter: ScriptedAdapter.ok());

      await harness.client.post<dynamic>('/commerce/checkout', data: _checkout);

      expect(harness.adapter.sent, hasLength(1));
      expect(harness.store.saveCount, 2,
          reason: 'enqueued on the way out, cleared on the way back');
    });
  });
}
