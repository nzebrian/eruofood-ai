<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Developer;

use EruoFood\PublicApi\Application\Service\ApplicationService;
use EruoFood\PublicApi\Application\Service\DeveloperService;
use EruoFood\PublicApi\Application\Transformer\PlatformTransformer;
use EruoFood\PublicApi\Interface\Http\Concerns\ResolvesDeveloper;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Application (API client) management for the authenticated developer. */
final class ApplicationController
{
    use ResolvesDeveloper;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly ApplicationService $applications,
        private readonly DeveloperService $developers,
        private readonly PlatformTransformer $transformer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $developerId = $this->developerId($request, $this->developers);
        $page = $this->applications->forDeveloper(
            $developerId,
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->collection($page, fn ($a): array => $this->transformer->application($a));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scopes' => ['array'],
            'scopes.*' => ['string'],
        ]);
        $developerId = $this->developerId($request, $this->developers);
        $application = $this->applications->create($developerId, $data['name'], $data['description'] ?? '', $data['scopes'] ?? []);

        return $this->item($this->transformer->application($application), [], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $developerId = $this->developerId($request, $this->developers);

        return $this->item($this->transformer->application($this->applications->get($id, $developerId)));
    }

    public function updateScopes(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['scopes' => ['required', 'array'], 'scopes.*' => ['string']]);
        $developerId = $this->developerId($request, $this->developers);

        return $this->item($this->transformer->application($this->applications->setScopes($id, $developerId, $data['scopes'])));
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $developerId = $this->developerId($request, $this->developers);

        return $this->item($this->transformer->application($this->applications->suspend($id, $developerId)));
    }
}
