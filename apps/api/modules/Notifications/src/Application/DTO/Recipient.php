<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\DTO;

/**
 * Who a notification is actually going to, resolved from a user id.
 *
 * The engine works in user ids; a channel needs an address. Keeping that
 * translation in one resolved value — rather than letting each channel query
 * the identity store its own way — is what makes "does this person still have a
 * deliverable address?" a single question with a single answer.
 *
 * Deliberately minimal. A channel adapter needs a name to greet somebody and a
 * locale to pick a template; it has no business knowing anything else about the
 * account, and cannot leak what it was never given.
 */
final readonly class Recipient
{
    public function __construct(
        public string $userId,
        public ?string $emailAddress,
        public ?string $displayName,
        public string $locale = 'en',
    ) {
    }

    public function hasEmail(): bool
    {
        return $this->emailAddress !== null && $this->emailAddress !== '';
    }

    /** A safe salutation: the first name if we have one, otherwise something neutral. */
    public function greetingName(): string
    {
        if ($this->displayName === null || trim($this->displayName) === '') {
            return 'there';
        }

        return explode(' ', trim($this->displayName))[0];
    }
}
