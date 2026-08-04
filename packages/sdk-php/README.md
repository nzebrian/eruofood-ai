# eruofood/sdk (PHP)

Minimal cURL-based client for the EruoFood Public API (PHP 8.2+).

```php
use EruoFood\Sdk\Client;

$client = new Client(getenv('EF_API_KEY'), 'https://api.eruofood.example/api/public/v1');

$page = $client->getPage('/foods', ['q' => 'jollof', 'per_page' => 20]);
foreach ($client->paginate('/foods') as $food) {
    // ...
}
```

Auth is via API key (Bearer). Non-2xx responses throw `EruoFood\Sdk\ApiException`.
