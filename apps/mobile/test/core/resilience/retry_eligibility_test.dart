import 'package:dio/dio.dart';
import 'package:eruofood/core/resilience/retry_eligibility.dart';
import 'package:flutter_test/flutter_test.dart';

RequestOptions _options(String path) => RequestOptions(path: path);

DioException _badResponse(int status) => DioException(
      requestOptions: _options('/commerce/checkout'),
      type: DioExceptionType.badResponse,
      response: Response<dynamic>(
        requestOptions: _options('/commerce/checkout'),
        statusCode: status,
      ),
    );

void main() {
  group('declared endpoints', () {
    test('every declaration names a scope, a method and an absolute path', () {
      expect(RetryEligibility.endpoints, isNotEmpty);

      for (final endpoint in RetryEligibility.endpoints) {
        expect(endpoint.scope, isNotEmpty);
        expect(endpoint.method, endpoint.method.toUpperCase());
        expect(endpoint.path, startsWith('/'));
      }
    });

    test('scopes are unique', () {
      final scopes =
          RetryEligibility.endpoints.map((e) => e.scope).toList(growable: false);

      expect(scopes.toSet(), hasLength(scopes.length));
    });

    test('every declared scope resolves back to its declaration', () {
      for (final endpoint in RetryEligibility.endpoints) {
        expect(RetryEligibility.forScope(endpoint.scope)?.path, endpoint.path);
      }
    });

    test('matches a declared endpoint', () {
      final endpoint =
          RetryEligibility.forRequest('POST', '/commerce/checkout');

      expect(endpoint, isNotNull);
      expect(endpoint!.scope, 'commerce.checkout');
      expect(endpoint.isMoneyMoving, isTrue);
    });

    test('matches through the api version prefix and a query string', () {
      // Dio may hand over the path either relative to the base URL or already
      // joined; both must resolve to the same declaration.
      expect(
        RetryEligibility.forRequest('POST', '/api/v1/commerce/checkout')?.scope,
        'commerce.checkout',
      );
      expect(
        RetryEligibility.forRequest(
                'POST', 'https://api.example.test/api/v1/commerce/checkout?x=1')
            ?.scope,
        'commerce.checkout',
      );
    });

    test('is method-sensitive', () {
      expect(RetryEligibility.forRequest('GET', '/commerce/checkout'), isNull);
    });

    test('refuses anything not declared', () {
      expect(RetryEligibility.forRequest('POST', '/commerce/wishlist'), isNull);
      expect(RetryEligibility.forRequest('POST', '/reviews'), isNull);
    });

    test('refuses every auth path, whatever else is declared', () {
      for (final path in <String>[
        '/auth/login',
        '/auth/register',
        '/auth/refresh',
        '/auth/logout',
        '/oauth/token',
      ]) {
        expect(RetryEligibility.forRequest('POST', path), isNull,
            reason: '$path must never be queued — these carry credentials.');
      }
    });
  });

  group('failure classification', () {
    test('a transport failure never reached a verdict', () {
      for (final type in <DioExceptionType>[
        DioExceptionType.connectionTimeout,
        DioExceptionType.sendTimeout,
        DioExceptionType.receiveTimeout,
        DioExceptionType.connectionError,
      ]) {
        expect(
          RetryEligibility.classify(
            DioException(requestOptions: _options('/x'), type: type),
          ),
          RetryClassification.transportFailed,
        );
      }
    });

    test('a 4xx is a refusal the server will repeat', () {
      for (final status in <int>[400, 401, 403, 404, 409, 422]) {
        expect(RetryEligibility.classify(_badResponse(status)),
            RetryClassification.serverRefused,
            reason: '$status must not be retried.');
      }
    });

    test('a refusal-before-work is retryable', () {
      // 408, 425 and 429 are the 4xx codes that mean "we did not do it".
      for (final status in <int>[408, 425, 429]) {
        expect(RetryEligibility.classify(_badResponse(status)),
            RetryClassification.transportFailed);
      }
    });

    test('a 5xx is indeterminate, not a refusal', () {
      // The server took the request and then broke; it may have committed.
      for (final status in <int>[500, 502, 503, 504]) {
        expect(RetryEligibility.classify(_badResponse(status)),
            RetryClassification.serverIndeterminate);
      }
    });

    test('an unrecognised failure is indeterminate', () {
      expect(
        RetryEligibility.classify(DioException(
          requestOptions: _options('/x'),
          type: DioExceptionType.unknown,
        )),
        RetryClassification.serverIndeterminate,
      );
      expect(
        RetryEligibility.classify(DioException(
          requestOptions: _options('/x'),
          type: DioExceptionType.badResponse,
        )),
        RetryClassification.serverIndeterminate,
      );
    });

    test('a cancelled or untrusted request is not retried', () {
      expect(
        RetryEligibility.classify(DioException(
            requestOptions: _options('/x'), type: DioExceptionType.cancel)),
        RetryClassification.serverRefused,
      );
      expect(
        RetryEligibility.classify(DioException(
            requestOptions: _options('/x'),
            type: DioExceptionType.badCertificate)),
        RetryClassification.serverRefused,
      );
    });
  });

  group('payload sanitisation', () {
    test('strips every credential-shaped key', () {
      final sanitised = RetryEligibility.sanitisePayload(<String, dynamic>{
        'email': 'a@b.test',
        'password': 'hunter2',
        'password_confirmation': 'hunter2',
        'access_token': 'secret',
        'card_number': '4111111111111111',
        'cvv': '123',
      });

      expect(sanitised, <String, dynamic>{'email': 'a@b.test'});
    });

    test('strips them from nested objects too', () {
      final sanitised = RetryEligibility.sanitisePayload(<String, dynamic>{
        'order': <String, dynamic>{'id': 'o1', 'secret_note': 'x'},
      });

      expect(sanitised, <String, dynamic>{
        'order': <String, dynamic>{'id': 'o1'},
      });
    });

    test('refuses a body it cannot reconstruct faithfully', () {
      // A stream, a FormData, an arbitrary object — replaying an approximation
      // of the original is worse than never queueing it.
      expect(RetryEligibility.sanitisePayload(FormData()), isNull);
      expect(RetryEligibility.sanitisePayload('raw string body'), isNull);
      expect(
        RetryEligibility.sanitisePayload(
            <String, dynamic>{'when': DateTime.utc(2026)}),
        isNull,
      );
    });

    test('an absent body is an empty payload, not a refusal', () {
      expect(RetryEligibility.sanitisePayload(null), <String, dynamic>{});
    });
  });
}
