<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\ScopeRegistry;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;

/** Public, unauthenticated meta endpoints: API status and the scope catalogue. */
final class MetaController
{
    use RespondsWithEnvelope;

    public function __construct(private readonly ScopeRegistry $scopes)
    {
    }

    public function status(): JsonResponse
    {
        return $this->item([
            'status' => 'ok',
            'version' => (string) config('publicapi.current_version', 'v1'),
            'versions' => (array) config('publicapi.versions', ['v1']),
            'deprecated' => array_keys((array) config('publicapi.deprecated', [])),
        ]);
    }

    public function scopes(): JsonResponse
    {
        $catalogue = [];
        foreach ($this->scopes->all() as $scope => $description) {
            $catalogue[] = ['scope' => $scope, 'description' => $description];
        }

        return $this->item(['scopes' => $catalogue]);
    }
}
