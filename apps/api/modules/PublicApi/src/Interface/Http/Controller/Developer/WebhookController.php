<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Developer;

use EruoFood\PublicApi\Application\Service\ApplicationService;
use EruoFood\PublicApi\Application\Service\DeveloperService;
use EruoFood\PublicApi\Application\Service\WebhookService;
use EruoFood\PublicApi\Application\Transformer\PlatformTransformer;
use EruoFood\PublicApi\Domain\Webhook\Webhook;
use EruoFood\PublicApi\Domain\Webhook\WebhookDelivery;
use EruoFood\PublicApi\Interface\Http\Concerns\ResolvesDeveloper;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook endpoint management, scoped to an application the developer owns.
 * The signing secret is returned once on create/rotate and never again.
 */
final class WebhookController
{
    use ResolvesDeveloper;
    use RespondsWithEnvelope;

    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly ApplicationService $applications,
        private readonly DeveloperService $developers,
        private readonly PlatformTransformer $transformer,
    ) {
    }

    public function index(Request $request, string $applicationId): JsonResponse
    {
        $this->assertOwnsApplication($request, $applicationId);
        $hooks = $this->webhooks->forApplication($applicationId);

        return $this->item(['webhooks' => array_map(fn (Webhook $w): array => $this->transformer->webhook($w), $hooks)]);
    }

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $this->assertOwnsApplication($request, $applicationId);
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
        ]);
        $webhook = $this->webhooks->subscribe($applicationId, $data['url'], $data['events']);

        // Secret shown once on creation.
        return $this->item($this->transformer->webhook($webhook, includeSecret: true), [], 201);
    }

    public function update(Request $request, string $applicationId, string $id): JsonResponse
    {
        $this->assertOwnsApplication($request, $applicationId);
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
        ]);

        return $this->item($this->transformer->webhook($this->webhooks->update($id, $applicationId, $data['url'], $data['events'])));
    }

    public function rotateSecret(Request $request, string $applicationId, string $id): JsonResponse
    {
        $this->assertOwnsApplication($request, $applicationId);

        return $this->item($this->transformer->webhook($this->webhooks->rotateSecret($id, $applicationId), includeSecret: true));
    }

    public function destroy(Request $request, string $applicationId, string $id): JsonResponse
    {
        $this->assertOwnsApplication($request, $applicationId);

        return $this->item($this->transformer->webhook($this->webhooks->disable($id, $applicationId)));
    }

    public function deliveries(Request $request, string $applicationId, string $id): JsonResponse
    {
        $this->assertOwnsApplication($request, $applicationId);
        $deliveries = $this->webhooks->deliveries($id, $applicationId, (int) $request->query('limit', '50'));

        return $this->item(['deliveries' => array_map(fn (WebhookDelivery $d): array => $this->transformer->delivery($d), $deliveries)]);
    }

    private function assertOwnsApplication(Request $request, string $applicationId): void
    {
        // Throws PublicApiForbidden/NotFound if the app is not the developer's.
        $this->applications->get($applicationId, $this->developerId($request, $this->developers));
    }
}
