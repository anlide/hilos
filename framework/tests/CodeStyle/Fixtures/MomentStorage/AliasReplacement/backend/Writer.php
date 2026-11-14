<?php

declare(strict_types=1);

namespace MomentAlias;

use function date;
use MomentFixtures\EventEntity;

class Writer
{
    public function write(EventEntity $entity): void
    {
        $now = date('Y-m-d H:i:s');
        $entity->at = (int)$now;
    }
}
