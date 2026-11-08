<?php

declare(strict_types=1);

namespace MomentFixtures;

use Hilos\Database\Database;
use Hilos\Utils\Helpers\TimeHelper;
use MomentFixtures\EventCollection;
use MomentFixtures\EventEntity;
use MomentFixtures\EventObject;

class DestructuringWriter
{
    public function brackets(EventEntity $entity): void
    {
        $when = time();
        [$when] = [TimeHelper::nowMs()];
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
        $entity->at = time();
        Database::sql('UPDATE event SET at = ?', [time()]);
    }

    public function lists(EventEntity $entity): void
    {
        $when = time();
        list($when) = [TimeHelper::nowMs()];
        $entity->at = $when;
        Database::sql('UPDATE event SET at = ?', [$when]);
        $entity->at = time();
        Database::sql('UPDATE event SET at = ?', [time()]);
    }

    public function nestedBrackets(EventEntity $entity): void
    {
        $first = time();
        $second = time();
        ['first' => $first, 'nested' => [, $second]] = ['first' => 0, 'nested' => [0, 0]];
        $entity->at = $first;
        $entity->created_at = $second;
        $first = time();
        $entity->at = $first;
    }

    public function nestedLists(EventEntity $entity): void
    {
        $first = time();
        $second = time();
        list($first, list(, $second)) = [0, [0, 0]];
        $entity->at = $first;
        $entity->created_at = $second;
        $second = time();
        Database::sql('UPDATE event SET created_at = ?', [$second]);
    }

    public function firstDestructuring(EventEntity $entity): void
    {
        [$first] = [0];
        $first = time();
        $entity->at = $first;
        list($second) = [0];
        $second = time();
        Database::sql('UPDATE event SET created_at = ?', [$second]);
    }

    public function receivers(EventEntity $entity, $unknown): void
    {
        [$entity] = [$unknown];
        $entity->at = time();
        $created = new EventEntity();
        list($created) = [$unknown];
        $created->at = time();
        $object = new EventObject();
        [$object] = [$unknown];
        $object->happenedAt = time();
    }

    public function listReceiver(EventObject $object, $unknown): void
    {
        list($object) = [$unknown];
        $object->happenedAt = time();
    }

    public function classNames($unknown): void
    {
        $bracketClass = EventCollection::OBJECT_CLASS;
        [$bracketClass] = [$unknown];
        $bracketObject = $bracketClass::create();
        $bracketObject->happenedAt = time();
        $listClass = EventCollection::OBJECT_CLASS;
        list($listClass) = [$unknown];
        $listObject = $listClass::create();
        $listObject->happenedAt = time();
    }

    public function secondReceiverBinding($unknown): void
    {
        [$created] = [$unknown];
        $created = new EventEntity();
        $created->at = time();
        list($objectClass) = [$unknown];
        $objectClass = EventCollection::OBJECT_CLASS;
        $object = $objectClass::create();
        $object->happenedAt = time();
    }

    public function keyedTarget(EventEntity $entity): void
    {
        $key = time();
        $when = time();
        [$key => $when] = [0 => TimeHelper::nowMs()];
        $entity->at = $when;
        $entity->created_at = $key;
    }

    public function keyedListTarget(EventEntity $entity): void
    {
        $key = time();
        $when = time();
        list($key => $when) = [0 => TimeHelper::nowMs()];
        $entity->at = $when;
        $entity->created_at = $key;
    }

    public function reads(EventEntity $entity): void
    {
        $when = time();
        $data = [$when, $entity];
        $read = $data[$when];
        $row = ['value' => $when];
        [$row[$when], $entity->duration] = [0, 0];
        $entity->at = $when;
        $entity->at = $when;
        $entity->created_at = time();
    }

    public function references(EventEntity $entity): void
    {
        $first = time();
        $second = time();
        $values = [0, 0];
        [&$first, &$second] = $values;
        $entity->at = $first;
        $entity->created_at = $second;
    }
}
