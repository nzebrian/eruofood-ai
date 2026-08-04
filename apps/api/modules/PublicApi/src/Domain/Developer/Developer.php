<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Developer;

use DateTimeImmutable;

/**
 * A developer account on the platform. It is a thin identity that links a
 * platform user (from the Identity context, by id) to their API applications.
 * Owning the account here keeps the public-API surface decoupled from Identity's
 * internals — only the user id crosses the boundary.
 */
final class Developer
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private string $name,
        private string $email,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function register(string $id, string $userId, string $name, string $email, DateTimeImmutable $now): self
    {
        return new self($id, $userId, $name, $email, $now);
    }

    public static function reconstitute(string $id, string $userId, string $name, string $email, DateTimeImmutable $createdAt): self
    {
        return new self($id, $userId, $name, $email, $createdAt);
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
