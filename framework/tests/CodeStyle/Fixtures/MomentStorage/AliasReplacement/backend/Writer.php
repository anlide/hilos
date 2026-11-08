<?php

declare(strict_types=1);

namespace MomentAlias;

use Hilos\Utils\Helpers\TimeHelper;
use MomentFixtures\EventEntity;

class Writer
{
    public function write(EventEntity $entity): void
    {
        $now = TimeHelper::getSqlDateTime();
        $entity->at = (int)$now;
    }
}
