<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Concerns;

use EruoFood\PublicApi\Domain\Authorization\Principal;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Builds the {@see Principal} from the request attributes set by the public-API
 * authentication middleware (API key or OAuth2 token — both populate the same
 * attributes). Every object-level authorization decision starts here, so it is
 * always the authenticated principal — never a client-supplied id — that owns a
 * resource lookup.
 */
trait ResolvesPrincipal
{
    protected function principal(Request $request): Principal
    {
        $applicationId = $request->attributes->get('publicapi_application_id');
        if (! is_string($applicationId)) {
            throw new RuntimeException('No authenticated public-API principal on the request.');
        }

        /** @var list<string> $scopes */
        $scopes = $request->attributes->get('publicapi_scopes', []);
        $subject = $request->attributes->get('publicapi_subject_user_id');

        return new Principal(
            $applicationId,
            (string) $request->attributes->get('publicapi_developer_id', ''),
            new ScopeSet($scopes),
            is_string($subject) && $subject !== '' ? $subject : null,
            (string) $request->attributes->get('publicapi_auth_via', 'api_key'),
        );
    }
}
