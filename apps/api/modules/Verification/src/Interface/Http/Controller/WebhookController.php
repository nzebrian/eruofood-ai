<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Controller;

use EruoFood\Verification\Application\DTO\WebhookHeaders;
use EruoFood\Verification\Application\Service\WebhookService;
use EruoFood\Verification\Domain\Exception\WebhookRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Inbound provider callbacks.
 *
 * Unauthenticated by necessity — the provider signs the payload instead — and
 * therefore the most exposed surface in this context. Three deliberate choices:
 *
 * - **A rejection says nothing.** Signature failure, replay and unknown
 *   reference all return a bare 401. An endpoint that explains why it refused a
 *   forgery is a tool for refining the forgery.
 * - **Nothing sensitive is logged.** A rejected delivery is recorded as a
 *   digest of the body, never the body: a webhook payload can carry a name and
 *   a document number, and application logs are the wrong place for either.
 * - **The raw body is read before anything parses it**, because the signature is
 *   computed over exactly those bytes.
 */
final readonly class WebhookController
{
    public function __construct(
        private WebhookService $webhooks,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $rawBody = $request->getContent();

        try {
            $applied = $this->webhooks->handle(
                $provider,
                $rawBody,
                WebhookHeaders::fromArray($request->headers->all()),
            );
        } catch (WebhookRejected $e) {
            $this->logger->warning('Verification webhook rejected.', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
                // A fingerprint, so repeat forgeries can be correlated without
                // the payload itself ever reaching the log.
                'body_sha256' => hash('sha256', $rawBody),
            ]);

            return new JsonResponse(['error' => ['code' => 'UNAUTHORIZED']], 401);
        }

        // 200 either way once authenticated: a duplicate is not an error, and
        // telling the provider otherwise invites pointless retries.
        return new JsonResponse(['received' => true, 'applied' => $applied]);
    }
}
