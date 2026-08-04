<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\ValueObject;

use EruoFood\Reviews\Domain\Enum\SubjectType;

/** The thing being reviewed: a (type, id) soft reference into another context. */
final readonly class Subject
{
    public function __construct(
        public SubjectType $type,
        public string $id,
    ) {
    }

    public function key(): string
    {
        return $this->type->value.':'.$this->id;
    }

    public function equals(Subject $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
