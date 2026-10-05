<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;

/** Refuses a mount whose actual key or object-row link disagrees with its collection. */
final class MountedCollectionKeyGuard
{
    /**
     * @throws InvalidMountedCollectionException When a mount key or object-row collection link disagrees
     */
    public static function assertMountedKeysAgree(): void
    {
        $db = Hilos::$db;
        if ($db === null) {
            return;
        }

        $frameworkKeys = $db instanceof HilosDbContext ? $db->frameworkRegistrations() : [];
        foreach ($db->getMountedEntities() as $key => $entityClass) {
            $objects = $db->mountedObjectCollection($key);
            $collectionClass = $objects::class;
            $declaredKey = $collectionClass::COLLECTION_KEY;
            if ($key !== $declaredKey) {
                throw new InvalidMountedCollectionException(
                    "Object collection {$collectionClass} is mounted under [{$key}] but declares COLLECTION_KEY [{$declaredKey}]"
                );
            }

            $objectClass = $collectionClass::OBJECT_CLASS;
            $declaredCollection = $objectClass::OBJECT_COLLECTION_CLASS;
            $compatible = $declaredCollection !== ''
                && is_subclass_of($declaredCollection, Objects::class)
                && ($collectionClass === $declaredCollection
                    || (isset($frameworkKeys[$key]) && is_subclass_of($collectionClass, $declaredCollection)));
            if (!$compatible) {
                $linkedKey = is_subclass_of($declaredCollection, Objects::class)
                    ? $declaredCollection::COLLECTION_KEY
                    : null;
                throw new InvalidMountedCollectionException(
                    "Object collection {$collectionClass} [{$key}] holds {$objectClass} ({$entityClass}),"
                    . ' but OBJECT_COLLECTION_CLASS links to ' . ($declaredCollection ?: '(missing)')
                    . ' [' . ($linkedKey ?? '(missing)') . ']'
                );
            }
        }
    }
}
