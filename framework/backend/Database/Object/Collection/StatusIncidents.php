<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\StatusIncidents as EntityStatusIncidents;
use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectStatusIncident>
 * @method ObjectStatusIncident|null first()
 * @method ObjectStatusIncident|null last()
 * @method ObjectStatusIncident|null get(int|string $key)
 * @method ObjectStatusIncident|null offsetGet(mixed $offset)
 */
class StatusIncidents extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusIncident::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusIncidents::class;
    public const string COLLECTION_KEY = HilosDbContext::statusIncidents;
}
