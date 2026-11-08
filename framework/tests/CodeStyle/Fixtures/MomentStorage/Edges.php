<?php

declare(strict_types=1);

namespace MomentFixtures;

use DateTimeImmutable;
use Hilos\Utils\Helpers\{TimeHelper as Clock};
use MomentFixtures\{EventEntity as Row};
use function date as sqlDate;

class NullableObject
{
    public const string ENTITY_CLASS = EventEntity::class;

    public function __set(string $name, mixed $incoming): void
    {
        match ($name) {
            'when' => $this->entity->at = $incoming === null ? null : (int)$incoming,
            default => null,
        };
    }
}

class TransformedObject
{
    public const string ENTITY_CLASS = EventEntity::class;

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'when' => $this->entity->at = $value * 1000,
            'constant' => $this->entity->at = null,
            default => null,
        };
    }
}

class RealProperty extends EventObject
{
    public int $happenedAt = 0;
}

class Edges
{
    public function nullable(NullableObject $object): void
    {
        $object->when = TIME();
    }

    public function newTimestamp(Row $entity): void
    {
        $date = new DateTimeImmutable();
        $entity->at = $date->GETTIMESTAMP();
    }

    public function groupedImports(Row $entity): void
    {
        /** @var Row $local */
        $local->at = Clock::getSqlDateTime();
        $entity->at = sqlDate("Y-m-d H:i:s");
    }

    public function falseMagic(TransformedObject $transformed, RealProperty $real): void
    {
        $transformed->when = time();
        $transformed->constant = time();
        $real->happenedAt = time();
    }

    public function nestedScopes(Row $entity): void
    {
        $closure = function (FakeClock $entity): void {
            $entity->at = time();
        };
        $arrow = fn(FakeClock $entity) => $entity->at = time();
        $anonymous = new class {
            public function write(FakeClock $entity): void
            {
                $entity->at = time();
            }
        };
    }

    public function redefinitions(Row $entity): void
    {
        $now = time();
        $now = time();
        $entity->at = $now;
        $next = time();
        unset($next);
        $entity->at = $next;
        $other = time();
        ++$other;
        $entity->at = $other;
    }
}

class ExtraEdges
{
    public const string ENTITY_CLASS = EventEntity::class;

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'when', 'other' => ($this->entity->at = (int)$value),
            default => null,
        };
    }

    public function multi(): void
    {
        $this->when = time();
        $this->other = time();
    }

    public function localDocAssignment(): void
    {
        /** @var EventEntity|null $entity */
        $entity = unknownRow();
        $entity->at = time();
    }

    public function shortDocAssignment(): void
    {
        /** @var Row */
        $entity = unknownRow();
        $entity->at = time();
    }
}

function unrelatedLocals(): void
{
    $when = 123;
}
