<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Hilos;

/** The mounted Entity declarations that opt their tables into the change log. */
final class JournaledTables
{
    /**
     * @return array<string, class-string<Entity>> Entity classes by their actual collection keys
     * @throws InvalidMountedCollectionException When a mounted collection has no valid Entity
     */
    public static function mounted(): array
    {
        $journaled = [];
        foreach (Hilos::$db?->getMountedEntities() ?? [] as $key => $entityClass) {
            if ($entityClass::_journaled) {
                $journaled[$key] = $entityClass;
            }
        }

        return $journaled;
    }
}
