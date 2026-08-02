# SDK Guide

Foundation SDKs for the EruoFood Public API in **TypeScript**, **PHP** and
**Dart**. They are intentionally minimal — a client, API-key auth, configuration,
typed errors, and a pagination helper. Resource methods are thin wrappers over
`get`/`getPage`, so new endpoints need no SDK release.

Packages:

- `packages/sdk-typescript` — `@eruofood/sdk`
- `packages/sdk-php` — `eruofood/sdk`
- `packages/sdk-dart` — `eruofood_sdk`

All three:

- Authenticate with an API key (Bearer).
- Take a configurable `baseUrl` (default points at the public API).
- Return the unwrapped `data` from `get`, and the full `{ data, meta }` page from
  `getPage`.
- Expose a `paginate()` helper that walks every page lazily.
- Throw a typed error (`EruoFoodApiError` / `ApiException` /
  `EruoFoodApiException`) carrying the envelope `code`, `message`, `details` and
  HTTP status.

## TypeScript

```ts
import { EruoFoodClient, EruoFoodApiError } from '@eruofood/sdk';

const client = new EruoFoodClient({
  apiKey: process.env.EF_API_KEY!,
  baseUrl: 'https://api.eruofood.example/api/public/v1',
});

try {
  const page = await client.foods({ q: 'jollof', per_page: 20 });
  console.log(page.data, page.meta.pagination);

  for await (const food of client.paginate('/foods')) {
    console.log(food);
  }
} catch (e) {
  if (e instanceof EruoFoodApiError) console.error(e.status, e.code, e.message);
}
```

## PHP

```php
use EruoFood\Sdk\Client;
use EruoFood\Sdk\ApiException;

$client = new Client(getenv('EF_API_KEY'), 'https://api.eruofood.example/api/public/v1');

try {
    $page = $client->getPage('/foods', ['q' => 'jollof', 'per_page' => 20]);
    foreach ($client->paginate('/foods') as $food) {
        // …
    }
} catch (ApiException $e) {
    error_log("{$e->status} {$e->errorCode}: {$e->getMessage()}");
}
```

## Dart

```dart
import 'package:eruofood_sdk/eruofood_sdk.dart';

final client = EruoFoodClient(apiKey: apiKey, baseUrl: 'https://api.eruofood.example/api/public/v1');

final page = await client.getPage('/foods', {'q': 'jollof', 'per_page': 20});
await for (final food in client.paginate('/foods')) {
  // …
}
```

## Error handling

Every non-2xx response maps to the standard envelope and is raised as the SDK's
typed error. Common codes: `PUBLICAPI_UNAUTHENTICATED` (401),
`PUBLICAPI_FORBIDDEN` (missing scope, 403), `PUBLICAPI_RATE_LIMITED` /
`PUBLICAPI_QUOTA_EXCEEDED` (429). On 429, back off using the `Retry-After` /
`X-RateLimit-Reset` headers.

## Scope of the foundation

These SDKs deliberately do **not** include generated per-endpoint models,
auto-retry, or OAuth flows. They cover the milestone's requirement — client,
auth, config, error handling, pagination — and are structured so those can be
layered on later without breaking the surface. Generated typed models can be
produced from [`packages/api-contracts/openapi.yaml`](../packages/api-contracts/openapi.yaml).
