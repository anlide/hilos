<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\ModerationRules as EntityModerationRules;
use Hilos\Database\Object\Item\ModerationRule as ObjectModerationRule;
use Hilos\Database\Object\Objects;

/**
 * Administrators' own moderation rules, loaded whole on first read.
 *
 * @extends Objects<ObjectModerationRule>
 * @method ObjectModerationRule|null first()
 * @method ObjectModerationRule|null last()
 * @method ObjectModerationRule|null get(int|string $key)
 * @method ObjectModerationRule|null offsetGet(mixed $offset)
 */
class ModerationRules extends Objects
{
    public const string OBJECT_CLASS = ObjectModerationRule::class;
    public const string ENTITY_COLLECTION_CLASS = EntityModerationRules::class;
    public const string COLLECTION_KEY = HilosDbContext::moderationRules;
}
