<?php

declare(strict_types=1);

namespace MomentFixtures;

use Hilos\Utils\Helpers\TimeHelper;

class Unknown
{
    public function receivers($entity, $object): void
    {
        $entity->at = time();
        $object->happenedAt = time();
    }

    public function magic(ReadOnlyObject $readOnly, UnknownMapping $unknown, EventObject $object): void
    {
        $readOnly->happenedAt = time();
        $unknown->happenedAt = time();
        $object->misleadingAt = time();
        $object->createdAt = time();
    }

    public function dynamics(EventEntity $entity, string $column, string $method, FakeClock $clock): void
    {
        $entity->$column = time();
        $entity[$column] = time();
        $entity->at = $clock->getTimestamp();
        $entity->at = $clock->$method();
        $entity->at = FakeClock::getSqlDateTime();
        $entity->at = TimeHelper::$method();
        $entity->at = 'time()';
        $entity->at = 'Y-m-d H:i:s';
    }

    public function aliases(EventEntity $entity, bool $choose): void
    {
        $now = time();
        $now = TimeHelper::nowMs();
        $entity->at = $now;
        $next = time();
        $next++;
        $entity->at = $next;
        $before = time();
        if ($choose) {
            $entity->at = $before;
        }
        $branch = TimeHelper::nowMs();
        if ($choose) {
            $branch = time();
        }
        $entity->at = $branch;
    }

    public function unknownCalls(EventEntity $entity): void
    {
        $entity->at = timestamp();
        EventEntity::get(['at' => time()]);
        $row = ['at' => time()];
        $quoted = '$entity->at = time();';
        // $entity->at = time();
        /* $entity->at = TimeHelper::getSqlDateTime(); */
    }

    public function switchReceivers($entity, EventEntity $known, int $mode, bool $choose): void
    {
        $alias = time();
        switch ($mode) {
            case 1:
                $entity->at = time();
                $known->at = $alias;
                break;
            default:
                $receiver = $choose ? $entity : $known;
                $receiver->at = time();
                break;
        }
    }
}

class ShadowedReceivers
{
    public function foreachReceiver(EventEntity $entity, array $rows): void
    {
        foreach ($rows as $entity) {
            $entity->at = time();
        }
    }

    public function catchReceiver(EventEntity $entity): void
    {
        try {
            unknownCall();
        } catch (FakeClock $entity) {
            $entity->at = time();
        }
    }
}

class ReassignedSetter
{
    public const string ENTITY_CLASS = EventEntity::class;

    public function __set(string $property, mixed $value): void
    {
        $value = TimeHelper::nowMs();
        match ($property) {
            'when' => $this->entity->at = $value,
            default => null,
        };
    }

    public function ignoredInput(): void
    {
        $this->when = time();
    }
}
