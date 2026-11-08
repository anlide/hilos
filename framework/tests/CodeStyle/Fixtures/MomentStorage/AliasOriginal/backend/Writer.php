<?php

declare(strict_types=1);

namespace MomentAlias;

use MomentFixtures\EventEntity;

class Writer
{
    public function write(EventEntity $entity): void
    {
        $now = time();
        $entity->at = (int)$now;
    }
}
