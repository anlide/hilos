<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\StepUp as EntityStepUp;

/**
 * StepUps entity collection.
 *
 * @extends EntityCollection<EntityStepUp>
 */
final class StepUps extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStepUp::class;
}
