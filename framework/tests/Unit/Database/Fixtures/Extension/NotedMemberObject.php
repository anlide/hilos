<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\VerifierCircleMember;

/**
 * The Object of the test chain: the framework's fields plus the project's `note`, reached by the
 * same magic the framework's fields are, and handed to the parent for everything else.
 *
 * @property ?string $note
 */
class NotedMemberObject extends VerifierCircleMember
{
    public const string ENTITY_CLASS = NotedMemberEntity::class;
    public const string note = 'note';

    /**
     * @param string $property Property name (note, or any of the framework's)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known field of the member
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::note => $this->entity->note,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Property name (note, or any of the framework's)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on the member
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::note => $this->entity->note = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * @return array<string, mixed> The framework's fields and the project's note
     */
    public function toArray(): array
    {
        return [...parent::toArray(), self::note => $this->entity->note];
    }
}
