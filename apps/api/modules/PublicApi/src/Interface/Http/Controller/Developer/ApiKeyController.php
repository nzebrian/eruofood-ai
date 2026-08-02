<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Developer;

use EruoFood\PublicApi\Application\Service\ApiKeyService;
use EruoFood\PublicApi\Application\Service\DeveloperService;
use EruoFood\PublicApi\Application\Transformer\PlatformTransformer;
use EruoFood\PublicApi\Domain\ApiKey\ApiKey;
use EruoFood\PublicApi\Interface\Http\Concerns\ResolvesDeveloper;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API key management. The plaintext key is returned exactly once, on issue/rotate. */
final class ApiKeyController
{
    use ResolvesDeveloper;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly DeveloperService $developers,
        private readonly PlatformTransformer $transformer,
    ) {
    }

    public function index(Request $request, string $applicationId): JsonResponse
    {
        $developerId = $this->developerId($request, $this->developers);
        $keys = $this->keys->forApplication($applicationId, $developerId);

        return $this->item(['keys' => array_map(fn (ApiKey $k): array => $this->transformer->apiKey($k), $keys)]);
    }

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['array'],
            'scopes.*' => ['string'],
            'ttl_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $developerId = $this->developerId($request, $this->developers);
        $issued = $this->keys->issue($applicationId, $developerId, $data['name'], $data['scopes'] ?? [], $data['ttl_days'] ?? null);

        return $this->item($this->transformer->issuedKey($issued->key, $issued->plaintext), [], 201);
    }

    public function rotate(Request $request, string $keyId): JsonResponse
    {
        $developerId = $this->developerId($request, $this->developers);
        $issued = $this->keys->rotate($keyId, $developerId);

        return $this->item($this->transformer->issuedKey($issued->key, $issued->plaintext), [], 201);
    }

    public function destroy(Request $request, string $keyId): JsonResponse
    {
        $developerId = $this->developerId($request, $this->developers);
        $this->keys->revoke($keyId, $developerId);

        return $this->item(['revoked' => true]);
    }
}
