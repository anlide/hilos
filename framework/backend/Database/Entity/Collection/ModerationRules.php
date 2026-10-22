<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\ModerationRule as EntityModerationRule;

/** @extends EntityCollection<EntityModerationRule> */
class ModerationRules extends EntityCollection
{
    public const string ENTITY_CLASS = EntityModerationRule::class;
}
