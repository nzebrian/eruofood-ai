import 'dart:async';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eruofood/core/config/app_config.dart';
import 'package:eruofood/core/network/api_client.dart';
import 'package:eruofood/core/resilience/pending_operation.dart';
import 'package:eruofood/core/resilience/retry_queue.dart';
import 'package:eruofood/core/resilience/retry_queue_interceptor.dart';

/// A test double for the *bottom* of Dio, not the top.
///
/// This is the whole point of the harness. Substituting `ApiClient`, or a fake
/// `Dio`, would let a test pass while the real transport bypassed the queue
/// entirely — the exact false pass the negative controls exist to rule out. By
/// replacing only `httpClientAdapter`, every layer under test is the production
/// one: the production `ApiClient`, the production interceptor chain, the
/// production `RetryQueue`. Only the socket is fake.
class ScriptedAdapter implements HttpClientAdapter {
  ScriptedAdapter(this._respond);

  /// Always succeeds with `{"data": {}}`.
  factory ScriptedAdapter.ok({int status = 201, String body = '{"data":{}}'}) =>
      ScriptedAdapter((_) async => ResponseBody.fromString(
            body,
            status,
            headers: <String, List<String>>{
              Headers.contentTypeHeader: <String>[Headers.jsonContentType],
            },
          ));

  /// Always fails the way a dropped connection does.
  factory ScriptedAdapter.connectionError() => ScriptedAdapter(
        (RequestOptions options) async => throw DioException(
          requestOptions: options,
          type: DioExceptionType.connectionError,
          message: 'Connection closed by the test harness.',
        ),
      );

  /// Always fails with a status the server actually answered.
  factory ScriptedAdapter.status(int status, {String body = '{"error":{}}'}) =>
      ScriptedAdapter(
        (RequestOptions options) async => throw DioException(
          requestOptions: options,
          type: DioExceptionType.badResponse,
          response: Response<dynamic>(
            requestOptions: options,
            statusCode: status,
            data: body,
          ),
        ),
      );

  final Future<ResponseBody> Function(RequestOptions options) _respond;

  /// Every request that actually reached the wire, in order.
  final List<RequestOptions> sent = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) {
    sent.add(options);
    return _respond(options);
  }

  @override
  void close({bool force = false}) {}
}

/// An in-memory [PendingOperationStore] that can be told to break.
class MemoryStore implements PendingOperationStore {
  List<PendingOperation> saved = const <PendingOperation>[];

  /// Number of `save` calls, so a test can prove an entry was removed exactly
  /// once rather than merely ending up absent.
  int saveCount = 0;

  /// When set, calls throw it — the "queue infrastructure is broken" case.
  Object? failure;

  /// Let this many saves succeed before [failure] starts being thrown, so a
  /// test can break storage *between* the enqueue and the removal.
  int failAfterSaves = 0;

  bool get _broken => failure != null && saveCount >= failAfterSaves;

  @override
  Future<List<PendingOperation>> load() async {
    final failure = this.failure;
    if (failure != null && _broken) throw failure;
    return saved;
  }

  @override
  Future<void> save(List<PendingOperation> operations) async {
    final failure = this.failure;
    if (failure != null && _broken) throw failure;
    saveCount++;
    saved = operations;
  }
}

/// A production [ApiClient] wired to a scripted socket and a real queue.
class Harness {
  Harness({
    required this.adapter,
    MemoryStore? store,
    List<String>? keys,
    DateTime? now,
    List<String>? diagnostics,
  })  : store = store ?? MemoryStore(),
        diagnostics = diagnostics ?? <String>[] {
    queue = RetryQueue(this.store);

    var minted = 0;
    final pool = keys ?? const <String>['key-1', 'key-2', 'key-3', 'key-4'];

    client = ApiClient(
      const AppConfig(
        apiBaseUrl: 'https://api.test/api/v1',
        appName: 'EruoFood AI (test)',
        appEnv: 'testing',
      ),
      tokenProvider: () async => token,
      interceptors: <Interceptor>[
        RetryQueueInterceptor(
          queue: queue,
          newIdempotencyKey: () => pool[minted++ % pool.length],
          now: () => clock,
          onDiagnostic: this.diagnostics.add,
        ),
      ],
    );

    client.raw.httpClientAdapter = adapter;
    clock = now ?? DateTime.utc(2026, 8, 24, 12);
  }

  final ScriptedAdapter adapter;
  final MemoryStore store;
  final List<String> diagnostics;

  late final RetryQueue queue;
  late final ApiClient client;

  /// Mutable so a test can prove replay picks up a *refreshed* token.
  String? token = 'access-token-v1';

  late DateTime clock;
}
