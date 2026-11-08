<?php

declare(strict_types=1);

namespace MomentShadow;

use MomentFixtures\EventEntity;
use function MomentLookalike\time as seconds;

function time(): int
{
    return 123;
}

function date(string $format): string
{
    return $format;
}

class Shadow
{
    public function local(EventEntity $entity): void
    {
        $entity->at = time();
        $entity->at = date('Y-m-d H:i:s');
        $entity->at = seconds();
    }

    public function global(EventEntity $entity): void
    {
        $entity->at = \time();
    }

    public function namedDates(EventEntity $entity): void
    {
        $entity->at = date(format: 'Y-m-d H:i:s');
        $entity->at = \MomentLookalike\gmdate(format: 'Y-m-d H:i:s');
    }
}
