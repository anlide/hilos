<?php

declare(strict_types=1);

namespace MomentFixtures;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Utils\Helpers\TimeHelper as Clock;
use MomentFixtures\EventEntity as Row;
use function time as seconds;
use function gmdate as utcDate;

class Bad
{
    public const string OBJECT_COLLECTION_CLASS = EventCollection::class;

    protected EventObject $object;
    protected Row $entity;

    public function native(Row $entity): void
    {
        $entity->at = time();
    }

    /** @param Row $entity Resolved documentation receiver */
    public function documented($entity): void
    {
        $entity->at = time();
    }

    public function localDoc($entity): void
    {
        /** @var Row $entity */
        $entity->at = time();
    }

    public function constructed(): void
    {
        $entity = new Row();
        $entity->at = time();
        Row::fromRow(['at' => \time()]);
    }

    public function castAlias(Row $entity): void
    {
        $entity->created_at = (int)(
            seconds(/* comment */)
        );
    }

    public function timestamp(Row $entity, DateTimeInterface $date): void
    {
        $entity->at = $date->getTimestamp();
        $entity->created_at = (new DateTimeImmutable())->getTimestamp();
    }

    public function dates(Row $entity): void
    {
        $entity->at = date('Y-m-d H:i:s');
        $entity->at = utcDate('Y-m-d ' . 'H:i:s');
        $entity->created_at = '2026-11-07 ' . '12:13:14.123';
    }

    public function helper(Row $entity): void
    {
        $entity->at = Clock::getSqlDateTime();
        $entity->created_at = \Hilos\Utils\Helpers\TimeHelper::getSqlDateTime();
    }

    public function arrays(Row $entity): void
    {
        $entity[Row::at] = time();
        $entity['created_at'] = Clock::getSqlDateTime();
        Row::fromRow(['at' => time(), Row::created_at => '2026-11-07 12:13:14']);
    }

    public function magic(EventObject $object): void
    {
        $object->happenedAt = time();
        $this->object->happenedAt = time();
        $created = new EventObject();
        $created->happenedAt = time();
        $created->happenedAt = time();
    }

    public function inherited(ChildObject $object): void
    {
        $object->happenedAt = time();
    }

    public function factory(): void
    {
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $object = $objectClass::create();
        $object->happenedAt = time();
    }

    public function directProperty(): void
    {
        $this->entity->at = time();
    }

    public function alias(Row $entity): void
    {
        $now = Clock::getSqlDateTime();
        $entity->at = (string)($now);
        $entity->at = (string)($now);
    }

    public function duplicate(Row $entity): void
    {
        $entity->at = time();
        // A different line and whitespace do not change identity.
        $entity -> at = time ( );
    }

    public function switchLabels(Row $entity, int $mode): void
    {
        switch ($mode) {
            case 1:
                $entity->at = time();
                break;
            default:
                $entity->created_at = time();
                break;
        }
    }

    public function switchAfterStatement(Row $entity, int $mode): void
    {
        switch ($mode) {
            case 1:
                time();
                $entity->at = time();
                break;
            default:
                time();
                $entity->created_at = time();
                break;
        }
    }

    public function switchExpressions(Row $entity, int $mode, bool $choose): void
    {
        switch ($mode) {
            case $choose ? 1 : 2:
                ($entity)->at = time();
                break;
            case pick(value: ($choose ? 3 : 4)):
                $entity['created_at'] = time();
                break;
            case (match ($mode) { 5 => 6, default => 7 }):
                $entity->at = time();
                break;
        }
    }

    public function namedDates(Row $entity): void
    {
        $entity->at = date(format: 'Y-m-d H:i:s');
        $entity->created_at = gmdate(format: 'Y-m-d H:i:s');
        $entity->at = utcDate(timestamp: 0, format: 'Y-m-d ' . 'H:i:s.u');
        $entity->created_at = \date(timestamp: 0, format: ('Y-m-d H:i:s.v'));
    }

    public function namedDateAlias(Row $entity): void
    {
        $when = utcDate(timestamp: 0, format: 'Y-m-d H:i:s');
        $entity->at = $when;
        $entity->at = $when;
    }
}
