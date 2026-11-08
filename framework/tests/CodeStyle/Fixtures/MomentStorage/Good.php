<?php

declare(strict_types=1);

namespace MomentFixtures;

use Hilos\Utils\Helpers\TimeHelper;

class Good
{
    public function valid(EventEntity $entity, int $offset): void
    {
        $entity->at = TimeHelper::nowMs();
        $entity->at = null;
        $entity->at = 0;
        $entity->at = -1234;
        $entity->at = 1700000000123;
        $entity->at = 123 + $offset;
        $entity->at = TimeHelper::nowMs() * 1000;
        $entity->birthday = date('Y-m-d');
        $entity->duration = time();
        time();
        date('Y-m-d H:i:s');
    }

    public function optOut(OtherEntity $entity): void
    {
        $entity->at = time();
    }

    public function outsideCatalog(EventEntity $entity): void
    {
        $entity->unmapped = time();
        $entity->id = time();
        $entity->foreign_id = time();
    }

    public function switchValues(EventEntity $entity, int $mode): void
    {
        switch ($mode) {
            case 1:
                $entity->at = TimeHelper::nowMs();
                $entity->at = null;
                break;
            default:
                $entity->created_at = 0;
                $entity->created_at = -5678;
                break;
        }
    }

    public function namedFormats(EventEntity $entity, string $format): void
    {
        $entity->at = date(format: $format);
        $entity->at = gmdate(timestamp: 0, format: $format);
        $entity->at = date(format: 'Y-m-d');
        $entity->at = gmdate(format: 'H:i:s');
        $entity->birthday = date(format: 'Y-m-d H:i:s');
        $entity->duration = gmdate(format: 'Y-m-d H:i:s');
        $entity->AT = time();
        $when = date(format: 'Y-m-d H:i:s');
        $when = TimeHelper::nowMs();
        $entity->at = $when;
    }
}
