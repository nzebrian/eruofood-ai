<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Authorization;

use EruoFood\PublicApi\Domain\Exception\PublicApiForbidden;
use EruoFood\PublicApi\Domain\ValueObject\Scope;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * The authenticated identity behind a public-API request, independent of how it
 * was authenticated (API key or OAuth2 token). It carries the application, the
 * granted scopes, and — for customer-scoped credentials — the subject user id.
 * Object-level authorization is decided against this principal, never against a
 * raw id from the request, which is what defeats BOLA/IDOR.
 */
final readonly class Principal
{
    public function __construct(
        public string $applicationId,
        public string $developerId,
        public ScopeSet $scopes,
        public ?string $subjectUserId = null,
        public string $authVia = 'api_key',
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return $this->scopes->grants(new Scope($scope));
    }

    public function isCustomerScoped(): bool
    {
        return $this->subjectUserId !== null;
    }

    /**
     * Resolve the customer user id this principal may act as, or fail. Used by
     * customer-owned resources (orders): an application-level credential with no
     * subject cannot read or write another customer's data.
     */
    public function requireSubjectUser(): string
    {
        if ($this->subjectUserId === null) {
            throw new PublicApiForbidden(
                'This credential is application-scoped; a customer-scoped credential (or OAuth2 user token) is required for this resource.',
            );
        }

        return $this->subjectUserId;
    }
}
