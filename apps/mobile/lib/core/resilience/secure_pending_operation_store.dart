import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'pending_operation.dart';
import 'retry_queue.dart';

/// The queue's production storage, on the platform keystore.
///
/// ## Why secure storage and not a plain file
///
/// Partly because it is the only persistence this app has — `TokenStore`
/// already uses it and nothing else is on the dependency list — but mostly
/// because of what a queue entry is. It holds an idempotency key that the
/// reconciliation endpoint will answer questions about, plus an order or
/// top-up body. None of that is a credential (the interceptor strips those
/// before they ever reach here), but all of it describes what a specific person
/// is buying, and it sits on disk for as long as the operation is unresolved.
/// Keychain/Keystore is the cheapest correct answer, not a paranoid one.
///
/// ## Corrupt data is quarantined, never silently dropped
///
/// If the stored JSON cannot be parsed, the queue starts empty — there is no
/// other way to keep running — but the raw text is moved aside under
/// [quarantineKey] first. A money-moving entry that vanishes because a byte
/// flipped is an unresolved charge nobody can reconcile, and "it started empty"
/// is not something the app may discover only from a support ticket.
class SecurePendingOperationStore implements PendingOperationStore {
  SecurePendingOperationStore(
    this._storage, {
    void Function(String message)? onDiagnostic,
  }) : _onDiagnostic = onDiagnostic;

  static const String storageKey = 'eruofood_retry_queue';
  static const String quarantineKey = 'eruofood_retry_queue_corrupt';

  final FlutterSecureStorage _storage;
  final void Function(String message)? _onDiagnostic;

  @override
  Future<List<PendingOperation>> load() async {
    final raw = await _storage.read(key: storageKey);
    if (raw == null || raw.isEmpty) return const <PendingOperation>[];

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) {
        throw const FormatException('Stored retry queue is not a list.');
      }

      return decoded
          .map((dynamic entry) =>
              PendingOperation.fromJson(Map<String, dynamic>.from(entry as Map)))
          .toList();
    } on Object catch (error) {
      // Keep the bytes. Whatever is in there is the only remaining evidence
      // that operations were pending at all.
      await _storage.write(key: quarantineKey, value: raw);
      await _storage.delete(key: storageKey);
      _onDiagnostic?.call(
        'retry_queue: stored queue could not be parsed and was quarantined '
        'under "$quarantineKey" ($error).',
      );

      return const <PendingOperation>[];
    }
  }

  @override
  Future<void> save(List<PendingOperation> operations) async {
    if (operations.isEmpty) {
      await _storage.delete(key: storageKey);
      return;
    }

    await _storage.write(
      key: storageKey,
      value: jsonEncode(
        operations.map((PendingOperation o) => o.toJson()).toList(),
      ),
    );
  }
}
