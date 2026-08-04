# eruofood_sdk (Dart)

Minimal client for the EruoFood Public API (Dart 3.5+, depends on `package:http`).

```dart
import 'package:eruofood_sdk/eruofood_sdk.dart';

final client = EruoFoodClient(apiKey: Platform.environment['EF_API_KEY']!);
final page = await client.getPage('/foods', {'q': 'jollof', 'per_page': 20});
await for (final food in client.paginate('/foods')) {
  // ...
}
```

Auth is via API key (Bearer). Non-2xx responses throw `EruoFoodApiException`.
