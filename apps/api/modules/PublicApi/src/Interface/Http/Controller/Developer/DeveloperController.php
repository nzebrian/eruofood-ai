<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Developer;

use EruoFood\PublicApi\Application\Service\DeveloperService;
use EruoFood\PublicApi\Application\Transformer\PlatformTransformer;
use EruoFood\PublicApi\Interface\Http\Concerns\ResolvesDeveloper;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Developer account management (JWT-authenticated portal). */
final class DeveloperController
{
    use ResolvesDeveloper;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly DeveloperService $developers,
        private readonly PlatformTransformer $transformer,
    ) {
    }

    /** Register (or return) the developer account for the authenticated user. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
        ]);
        $developer = $this->developers->registerFor($this->currentUserId($request), $data['name'], $data['email']);

        return $this->item($this->transformer->developer($developer), [], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $developer = $this->developers->forUser($this->currentUserId($request));

        return $this->item($this->transformer->developer($developer));
    }
}
