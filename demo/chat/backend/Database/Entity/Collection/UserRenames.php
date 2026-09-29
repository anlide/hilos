<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Database\Entity\Collection\UserRenames as FrameworkUserRenames;

/**
 * UserRenames - Entity collection of chat's rename journal, re-pointed at chat's row.
 */
final class UserRenames extends FrameworkUserRenames
{
    public const string ENTITY_CLASS = EntityUserRename::class;
}
