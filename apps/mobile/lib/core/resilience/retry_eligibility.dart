/// Which requests may be queued, and what a failure actually tells us.
///
/// ## Why eligibility is declared rather than inferred
///
/// The tempting rule is "queue anything that failed with a network error". It
/// is wrong for the same reason the server refuses to guess: a request the app
/// never got an answer to may have taken effect. Whether resending is safe is a
/// property of the *endpoint*, not of the error — and the server already
/// published that property as its idempotency scopes.
///
/// So this file names the endpoints the backend guards with an idempotency
/// scope, and nothing else is ever queued. Adding an entry here without a
/// matching scope in `IdempotencyCoverageTest`'s list on the server would queue
/// a request the server will happily execute twice.
library;

import 'package:dio/dio.dart';

/// One endpoint the client may queue, and the server's own scope for it.
///
/// [scope] must match a scope in the API's idempotency contract exactly. It is
/// sent to `POST /reconcile`, which looks the key up under that scope; a
/// mismatch answers `never_received` for an operation the server has, which is
/// the one wrong answer that turns a retry into a double charge.
class RetryableEndpoint {
  const RetryableEndpoint({
    required this.scope,
    required this.method,
    required this.path,
    required this.isMoneyMoving,
  });

  final String scope;

  /// Upper-case, as Dio reports it.
  final String method;

  /// The path as the feature data source writes it, relative to the API base.
  final String path;

  /// Whether replaying this blind could move money or create an irreversible
  /// commitment. Everything currently declared is money-moving; see the note on
  /// [RetryEligibility.endpoints].
  final bool isMoneyMoving;
}

/// Why a request failed, expressed as what the client may now assume.
enum RetryClassification {
  /// The request never reached a verdict — timeout, connection refused, DNS.
  /// It stays queued. That is *not* permission to resend it: whether a resend
  /// is safe is still the server's call, answered by reconciliation.
  transportFailed,

  /// The server answered and refused: validation, authorisation, conflict, a
  /// malformed body. Nothing is pending, so the entry leaves the queue. Retrying
  /// a 422 produces a second 422 and a queue that never drains.
  serverRefused,

  /// The server answered, badly — a 5xx, or an error shape this build does not
  /// recognise. It may have acted before it broke. Stays queued, reconciled
  /// before anything is sent again.
  serverIndeterminate,
}

/// The rules, as static data plus two pure functions.
abstract final class RetryEligibility {
  /// Paths that must never be queued whatever else says otherwise.
  ///
  /// These carry raw credentials in the request body — `/auth/register` and
  /// `/auth/login` both send `password` — and a queue is a file on a device.
  /// This list is a hard floor under [endpoints]: a future contributor who adds
  /// an auth path to the declarations still gets nothing queued.
  static const List<String> deniedPathPrefixes = <String>[
    '/auth',
    '/oauth',
    '/password',
    '/me/password',
  ];

  /// Request-body keys that are stripped before an operation is persisted.
  ///
  /// Matched case-insensitively as substrings, because the shapes vary
  /// (`password`, `password_confirmation`, `current_password`).
  static const List<String> redactedPayloadKeys = <String>[
    'password',
    'token',
    'secret',
    'authorization',
    'pin',
    'cvv',
    'card_number',
  ];

  /// The endpoints this app may queue.
  ///
  /// Every entry corresponds to a scope in the server's idempotency contract
  /// and to a call that actually exists in a feature data source. Three server
  /// scopes are deliberately absent — `payments.refund`,
  /// `payments.wallet.transfer` and `dispatch.accept` — because no mobile
  /// feature calls them. Declaring an endpoint the app never sends would be a
  /// rule nothing exercises, which is how a registry drifts out of truth.
  ///
  /// All four are money-moving. That is not an oversight: the app's only
  /// queue-worthy writes are the ones that commit stock, redeem a coupon or
  /// open a charge. A non-money entry would need a server scope to collapse its
  /// duplicate, and none of the harmless endpoints has one.
  static const List<RetryableEndpoint> endpoints = <RetryableEndpoint>[
    RetryableEndpoint(
      scope: 'commerce.checkout',
      method: 'POST',
      path: '/commerce/checkout',
      isMoneyMoving: true,
    ),
    RetryableEndpoint(
      scope: 'marketplace.checkout',
      method: 'POST',
      path: '/checkout',
      isMoneyMoving: true,
    ),
    RetryableEndpoint(
      scope: 'payments.initiate',
      method: 'POST',
      path: '/payments/payments',
      isMoneyMoving: true,
    ),
    RetryableEndpoint(
      scope: 'payments.wallet.topup',
      method: 'POST',
      path: '/payments/wallet/topup',
      isMoneyMoving: true,
    ),
  ];

  /// The declaration for a request, or null if it may not be queued.
  ///
  /// The deny list is checked first and wins, so an auth path can never be
  /// queued even if somebody declares it above.
  static RetryableEndpoint? forRequest(String method, String path) {
    final normalisedPath = _normalisePath(path);

    for (final prefix in deniedPathPrefixes) {
      if (normalisedPath.startsWith(prefix)) return null;
    }

    final normalisedMethod = method.toUpperCase();

    for (final endpoint in endpoints) {
      if (endpoint.method == normalisedMethod && endpoint.path == normalisedPath) {
        return endpoint;
      }
    }

    return null;
  }

  /// The declaration for a scope, used when replaying a stored operation.
  static RetryableEndpoint? forScope(String scope) {
    for (final endpoint in endpoints) {
      if (endpoint.scope == scope) return endpoint;
    }
    return null;
  }

  /// What a Dio failure lets the client assume.
  ///
  /// The conservative direction is [serverIndeterminate]: an unrecognised
  /// failure is treated as "the server may have acted", which keeps the entry
  /// queued for reconciliation. The expensive mistake is the other one —
  /// classifying an ambiguous failure as refused, dropping the record, and
  /// leaving a charge nobody is tracking.
  static RetryClassification classify(DioException error) {
    switch (error.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.connectionError:
        return RetryClassification.transportFailed;

      case DioExceptionType.cancel:
        // The caller withdrew it. Nothing is pending on the server's side that
        // the client did not itself abandon.
        return RetryClassification.serverRefused;

      case DioExceptionType.badCertificate:
        // A TLS failure is not a transient network blip, and retrying into an
        // untrusted endpoint is worse than failing.
        return RetryClassification.serverRefused;

      case DioExceptionType.badResponse:
        return _fromStatus(error.response?.statusCode);

      case DioExceptionType.transformTimeout:
        // The server answered; the *client* then ran out of time decoding the
        // body. So the request arrived and may well have committed, and the
        // failure says nothing about what the server did with it. Treating a
        // decode timeout as a refusal would drop the record for a charge that
        // very likely succeeded.
        return RetryClassification.serverIndeterminate;

      case DioExceptionType.unknown:
        // Usually a wrapped SocketException, sometimes a parse error on a
        // response the server did send. Ambiguous, so treated as ambiguous.
        return RetryClassification.serverIndeterminate;
    }
  }

  static RetryClassification _fromStatus(int? status) {
    if (status == null) return RetryClassification.serverIndeterminate;

    if (status >= 500) {
      // The server took the request and then broke. It may have committed.
      return RetryClassification.serverIndeterminate;
    }

    if (status == 408 || status == 425 || status == 429) {
      // Refused *before* doing the work: a timeout on read, an early-data
      // replay refusal, a rate limit. Nothing took effect.
      return RetryClassification.transportFailed;
    }

    if (status >= 400) {
      // Validation, auth, conflict, not-found, malformed. The server answered
      // and its answer will not change on a resend.
      return RetryClassification.serverRefused;
    }

    // A 1xx/3xx surfacing as an error is not something this build understands.
    return RetryClassification.serverIndeterminate;
  }

  /// A copy of a request body with credential-shaped keys removed.
  ///
  /// Returns null when the body is not a JSON object — `FormData`, a stream, a
  /// raw string. Those cannot be reconstructed faithfully from storage, and an
  /// operation replayed with a body that is *nearly* the original is worse than
  /// one that was never queued.
  static Map<String, dynamic>? sanitisePayload(Object? body) {
    if (body == null) return const <String, dynamic>{};
    if (body is! Map) return null;

    final sanitised = <String, dynamic>{};

    for (final entry in body.entries) {
      final key = entry.key.toString();
      if (isRedactedKey(key)) continue;

      final value = entry.value;
      if (value is Map) {
        final nested = sanitisePayload(value);
        if (nested == null) return null;
        sanitised[key] = nested;
        continue;
      }
      if (value is List) {
        sanitised[key] = value;
        continue;
      }
      if (value == null || value is String || value is num || value is bool) {
        sanitised[key] = value;
        continue;
      }

      // An object with no JSON representation. Refusing beats guessing.
      return null;
    }

    return sanitised;
  }

  static bool isRedactedKey(String key) {
    final lower = key.toLowerCase();
    for (final needle in redactedPayloadKeys) {
      if (lower.contains(needle)) return true;
    }
    return false;
  }

  /// Strip the base-URL prefix Dio may have already applied, and any query.
  static String _normalisePath(String path) {
    var result = path;

    final queryAt = result.indexOf('?');
    if (queryAt >= 0) result = result.substring(0, queryAt);

    // A data source may pass an absolute URL, and Dio stores `path` verbatim.
    final schemeAt = result.indexOf('://');
    if (schemeAt >= 0) {
      final afterScheme = result.substring(schemeAt + 3);
      final slashAt = afterScheme.indexOf('/');
      result = slashAt >= 0 ? afterScheme.substring(slashAt) : '/';
    }

    // The base URL ends in `/api/v1`; declarations are written relative to it.
    const versionPrefix = '/api/v1';
    if (result.startsWith(versionPrefix)) {
      result = result.substring(versionPrefix.length);
    }

    if (result.length > 1 && result.endsWith('/')) {
      result = result.substring(0, result.length - 1);
    }

    return result.startsWith('/') ? result : '/$result';
  }
}
