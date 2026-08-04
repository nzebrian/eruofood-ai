<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Domain\Exception\PublicApiInvalidState;
use EruoFood\PublicApi\Domain\ValueObject\Scope;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * The authority on which scopes exist. Applications may only be granted scopes
 * from this catalogue (config-driven), so a typo or an unknown scope is rejected
 * rather than silently granting nothing.
 */
final readonly class ScopeRegistry
{
    /**
     * @param array<string, string> $catalogue scope => description
     */
    public function __construct(private array $catalogue)
    {
    }

    public function has(string $scope): bool
    {
        return array_key_exists($scope, $this->catalogue);
    }

    /**
     * @param list<string> $requested
     */
    public function validate(array $requested): ScopeSet
    {
        foreach ($requested as $scope) {
            if (! $this->has((string) $scope)) {
                throw new PublicApiInvalidState(sprintf('Unknown scope "%s".', $scope));
            }
        }

        return new ScopeSet(array_map('strval', $requested));
    }

    public function describe(Scope $scope): string
    {
        return $this->catalogue[$scope->value] ?? '';
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->catalogue;
    }
}
