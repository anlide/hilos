<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\StatusWorkComponents as EntityStatusWorkComponents;
use Hilos\Database\Object\Item\StatusWorkComponent as ObjectStatusWorkComponent;
use Hilos\Database\Object\Objects;

/**
 * @extends Objects<ObjectStatusWorkComponent>
 * @method ObjectStatusWorkComponent|null first()
 * @method ObjectStatusWorkComponent|null last()
 * @method ObjectStatusWorkComponent|null get(int|string $key)
 * @method ObjectStatusWorkComponent|null offsetGet(mixed $offset)
 */
class StatusWorkComponents extends Objects
{
    public const string OBJECT_CLASS = ObjectStatusWorkComponent::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStatusWorkComponents::class;
    public const string COLLECTION_KEY = HilosDbContext::statusWorkComponents;
}
