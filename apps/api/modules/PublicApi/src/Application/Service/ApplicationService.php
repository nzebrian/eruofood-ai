<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Application\Application;
use EruoFood\PublicApi\Domain\Application\ApplicationRepository;
use EruoFood\PublicApi\Domain\Exception\PublicApiNotFound;
use EruoFood\Shared\Domain\Paginated;

/**
 * Developer applications (API clients). An application is the scope-grant
 * boundary; keys and webhooks hang off it. Ownership is enforced on every
 * lookup so one developer can never touch another's application.
 */
final readonly class ApplicationService
{
    public function __construct(
        private ApplicationRepository $applications,
        private ScopeRegistry $scopes,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public function create(string $developerId, string $name, string $description, array $scopes): Application
    {
        $granted = $this->scopes->validate($scopes);
        $application = Application::create(
            $this->applications->nextIdentity(),
            $developerId,
            $name,
            $description,
            $granted,
            new DateTimeImmutable(),
        );
        $this->applications->save($application);

        return $application;
    }

    /**
     * @return Paginated<Application>
     */
    public function forDeveloper(string $developerId, int $page, int $perPage): Paginated
    {
        return $this->applications->forDeveloper($developerId, $page, $perPage);
    }

    public function get(string $id, string $developerId): Application
    {
        $application = $this->applications->findById($id) ?? throw PublicApiNotFound::of('application', $id);
        $application->isOwnedBy($developerId);

        return $application;
    }

    /**
     * @param list<string> $scopes
     */
    public function setScopes(string $id, string $developerId, array $scopes): Application
    {
        $application = $this->get($id, $developerId);
        $application->setScopes($this->scopes->validate($scopes), new DateTimeImmutable());
        $this->applications->save($application);

        return $application;
    }

    public function suspend(string $id, string $developerId): Application
    {
        $application = $this->get($id, $developerId);
        $application->suspend(new DateTimeImmutable());
        $this->applications->save($application);

        return $application;
    }
}
