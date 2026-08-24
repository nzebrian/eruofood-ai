import 'dart:math';

/// Mints the client-side idempotency keys the retry queue is built around.
///
/// A separate file with a single function because the property that matters is
/// unguessability, not formatting. The key is the join between a request the
/// app sent and the server's later answer about it, and `POST /reconcile`
/// answers on `(account, scope, key)` — so a predictable key is not a
/// cross-account leak, but it is still a way for one client to collide with its
/// own earlier operation and be told the wrong thing.
///
/// [Random.secure] rather than [Random] for exactly that reason. There is no
/// `uuid` package on this project's dependency list and adding one to format
/// 128 bits of entropy would be a lockfile change for no behavioural gain, so
/// the bytes are rendered as hex directly.
String newIdempotencyKey([Random? random]) {
  final source = random ?? Random.secure();
  final buffer = StringBuffer('efm-');

  // 16 bytes — the same width as a UUID, which is the shape the server's
  // `max:255` validation and every existing key in the system already expect.
  for (var i = 0; i < 16; i++) {
    buffer.write(source.nextInt(256).toRadixString(16).padLeft(2, '0'));
  }

  return buffer.toString();
}
