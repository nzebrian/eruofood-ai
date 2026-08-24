import 'package:eruofood/core/error/failure.dart';
import 'package:eruofood/core/resilience/retry_queue_interceptor.dart';
import 'package:eruofood/features/commerce/data/datasources/commerce_remote_data_source.dart';
import 'package:eruofood/features/commerce/data/repositories/commerce_repository_impl.dart';
import 'package:eruofood/features/commerce/domain/entities/commerce_entities.dart';
import 'package:eruofood/features/commerce/domain/repositories/commerce_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../core/resilience/support/transport_harness.dart';

/// A real feature, end to end, on the production classes.
///
/// This is the test the whole milestone exists for. Everything above it can be
/// green while no feature is actually protected: the queue's own tests
/// construct a `RetryQueue` directly, and the interceptor's tests call
/// `ApiClient` directly. Neither proves that the code a customer's tap runs
/// through — `CommerceRepositoryImpl` → `CommerceRemoteDataSource` →
/// `ApiClient` → Dio — reaches the queue at all.
///
/// So nothing here is substituted except the socket. The repository, the data
/// source, the client and the interceptor chain are the ones registered in
/// `injector.dart`.
const Map<String, dynamic> _checkoutPayload = <String, dynamic>{
  'address_id': 'addr-1',
  'pickup': false,
};

void main() {
  CommerceRepository repositoryOn(Harness harness) => CommerceRepositoryImpl(
        CommerceRemoteDataSource(harness.client),
      );

  test('a customer checkout that loses the connection is queued', () async {
    final harness = Harness(adapter: ScriptedAdapter.connectionError());

    final result =
        await repositoryOn(harness).checkout(_checkoutPayload);

    // The customer is told it failed. That is the truth, and the queue does not
    // get to soften it.
    expect(result.isLeft(), isTrue);
    expect(
      result.fold((Failure f) => f.message, (OrderSummaryView _) => null),
      isNotNull,
    );

    // And the operation is on the queue, keyed by the scope the server knows.
    final queued = harness.queue.operations.single;
    expect(queued.scope, 'commerce.checkout');
    expect(queued.endpoint, '/commerce/checkout');
    expect(queued.isMoneyMoving, isTrue);
    expect(queued.payload, _checkoutPayload);
    expect(queued.attempts, 1);
  });

  test('the same checkout, when it succeeds, leaves nothing queued', () async {
    final harness = Harness(
      adapter: ScriptedAdapter.ok(
        body: '{"data":{"id":"o-1","reference":"EF-1","status":"placed",'
            '"total_minor":250000,"currency":"NGN"}}',
      ),
    );

    final result = await repositoryOn(harness).checkout(_checkoutPayload);

    expect(result.isRight(), isTrue);
    expect(harness.queue.operations, isEmpty);

    // It still carried an idempotency key on the way out — that is what makes
    // the server able to answer for it later.
    expect(
      harness.adapter.sent.single.headers[RetryQueueInterceptor.headerName],
      isNotNull,
    );
  });

  test('a rejected checkout is reported and not left retrying forever',
      () async {
    final harness = Harness(adapter: ScriptedAdapter.status(422));

    final result = await repositoryOn(harness).checkout(_checkoutPayload);

    expect(result.isLeft(), isTrue);
    expect(harness.queue.operations, isEmpty);
  });

  test('browsing the catalogue is never queued', () async {
    // A GET has nothing to reconcile and no side effect to collapse. If reads
    // were being queued the queue would fill with noise and the money-moving
    // entries would be the hardest thing to find in it.
    final harness = Harness(
      adapter: ScriptedAdapter.ok(status: 200, body: '{"data":[]}'),
    );

    final result = await repositoryOn(harness).products();

    expect(result.isRight(), isTrue);
    expect(harness.queue.operations, isEmpty);
    expect(harness.store.saveCount, 0);
  });

  test('adding to the cart is not queued — the server has no scope for it',
      () async {
    // `commerce.checkout` is in the server's idempotency contract;
    // `cart/items` is not. Queueing it would replay a write the server has no
    // way to collapse.
    final harness = Harness(
      adapter: ScriptedAdapter.connectionError(),
    );

    final result = await repositoryOn(harness).addToCart('p-1', 2);

    expect(result.isLeft(), isTrue);
    expect(harness.queue.operations, isEmpty);
  });
}
