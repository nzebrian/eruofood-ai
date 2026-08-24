import 'dart:convert';

import 'package:eruofood/core/resilience/pending_operation.dart';
import 'package:eruofood/core/resilience/secure_pending_operation_store.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

/// The platform keystore, replaced by a map.
///
/// Subclassed rather than mocked so the production `read`/`write`/`delete`
/// signatures are the ones exercised: if `flutter_secure_storage` changes them,
/// this stops compiling instead of quietly testing a shape nothing uses.
class _FakeSecureStorage extends FlutterSecureStorage {
  const _FakeSecureStorage(this.values);

  final Map<String, String> values;

  @override
  Future<String?> read({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async =>
      values[key];

  @override
  Future<void> write({
    required String key,
    required String? value,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    if (value == null) {
      values.remove(key);
      return;
    }
    values[key] = value;
  }

  @override
  Future<void> delete({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    values.remove(key);
  }
}

PendingOperation _operation(String key) => PendingOperation(
      idempotencyKey: key,
      scope: 'commerce.checkout',
      endpoint: '/commerce/checkout',
      payload: const <String, dynamic>{'address_id': 'addr-1'},
      createdAt: DateTime.utc(2026, 8, 24, 11, 30),
      attempts: 2,
      lastAttemptAt: DateTime.utc(2026, 8, 24, 11, 45),
      isMoneyMoving: true,
    );

void main() {
  test('an empty keystore yields an empty queue', () async {
    final store = SecurePendingOperationStore(
      const _FakeSecureStorage(<String, String>{}),
    );

    expect(await store.load(), isEmpty);
  });

  test('an operation survives the full save/load lifecycle intact', () async {
    final values = <String, String>{};
    final store =
        SecurePendingOperationStore(_FakeSecureStorage(values));

    await store.save(<PendingOperation>[_operation('k1')]);

    // A fresh instance, as after a cold start.
    final revived = await SecurePendingOperationStore(
      _FakeSecureStorage(values),
    ).load();

    final restored = revived.single;
    expect(restored.idempotencyKey, 'k1');
    expect(restored.scope, 'commerce.checkout');
    expect(restored.endpoint, '/commerce/checkout');
    expect(restored.payload, <String, dynamic>{'address_id': 'addr-1'});
    expect(restored.attempts, 2);
    expect(restored.isMoneyMoving, isTrue);
    expect(restored.createdAt, DateTime.utc(2026, 8, 24, 11, 30));
    expect(restored.lastAttemptAt, DateTime.utc(2026, 8, 24, 11, 45));
  });

  test('an emptied queue clears the key rather than storing "[]"', () async {
    final values = <String, String>{};
    final store = SecurePendingOperationStore(_FakeSecureStorage(values));

    await store.save(<PendingOperation>[_operation('k1')]);
    await store.save(const <PendingOperation>[]);

    expect(values.containsKey(SecurePendingOperationStore.storageKey), isFalse);
  });

  test('corrupt data is quarantined, not silently discarded', () async {
    // A queue that vanishes because a byte flipped is an unresolved charge
    // nobody can reconcile. The bytes are the only remaining evidence.
    final values = <String, String>{
      SecurePendingOperationStore.storageKey: 'not json at all',
    };
    final diagnostics = <String>[];
    final store = SecurePendingOperationStore(
      _FakeSecureStorage(values),
      onDiagnostic: diagnostics.add,
    );

    expect(await store.load(), isEmpty);
    expect(values[SecurePendingOperationStore.quarantineKey], 'not json at all');
    expect(values.containsKey(SecurePendingOperationStore.storageKey), isFalse);
    expect(diagnostics.single, contains('quarantined'));
  });

  test('a well-formed but wrongly-shaped document is quarantined too',
      () async {
    final values = <String, String>{
      SecurePendingOperationStore.storageKey: jsonEncode(<String, dynamic>{
        'operations': <dynamic>[],
      }),
    };
    final store = SecurePendingOperationStore(_FakeSecureStorage(values));

    expect(await store.load(), isEmpty);
    expect(values.containsKey(SecurePendingOperationStore.quarantineKey), isTrue);
  });
}
