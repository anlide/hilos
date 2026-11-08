<?php

declare(strict_types=1);

namespace MomentNamespaceFirst;

use MomentFixtures\EventEntity;
use function time as clock;

class Writer
{
    public function write(EventEntity $entity): void
    {
        $entity->at = clock();
    }
}

namespace MomentNamespaceSecond;

use MomentFixtures\EventEntity;
use function MomentLookalike\time as clock;

class Writer
{
    public function write(EventEntity $entity): void
    {
        $entity->at = clock();
    }
}
