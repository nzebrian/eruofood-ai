# @eruofood/sdk (TypeScript)

Minimal, dependency-free client for the EruoFood Public API.

```ts
import { EruoFoodClient } from '@eruofood/sdk';

const client = new EruoFoodClient({ apiKey: process.env.EF_API_KEY!, baseUrl: 'https://api.eruofood.example/api/public/v1' });

const page = await client.foods({ q: 'jollof', per_page: 20 });
for await (const food of client.paginate('/foods')) {
  console.log(food);
}
```

Auth is via API key (Bearer). All errors throw `EruoFoodApiError` with the standard `code`/`message`/`details`.
See `docs/SDK_GUIDE.md` for the full guide.
