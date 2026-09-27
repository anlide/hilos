<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Entity\Collection\AccountDeletions as EntityAccountDeletions;
use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\Object\Objects;

/**
 * Deliberately broken sample: a framework object collection closed back both ways. It
 * declares the framework's own namespace on purpose — that is the zone, and the same
 * file in any other namespace would be a demo's chain, which the rule leaves alone.
 * Every line below must be reported by ORM-CHAIN-OPEN: the `final`, then each row built
 * by naming the framework class rather than through the link constants. The fully
 * qualified spelling at the end is caught twice, by CODE-FQN for how it is written and
 * by this rule for what it builds.
 *
 * @extends Objects<ObjectAccountDeletion>
 */
final class OrmChainSamples extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountDeletion::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountDeletions::class;

    /**
     * @param int $id Row key
     * @return array<int, mixed> Rows built by naming the framework class
     */
    public function byName(int $id): array
    {
        $entity = EntityAccountDeletion::getById($id);

        return [
            ObjectAccountDeletion::create(),
            $entity === null ? null : ObjectAccountDeletion::fromEntity($entity),
            EntityAccountDeletion::get([EntityAccountDeletion::id => $id]),
            EntityAccountDeletion::getAll(),
            EntityAccountDeletion::getEmpty(),
            EntityAccountDeletion::fromRow([]),
            new EntityAccountDeletion(),
            \Hilos\Database\Object\Item\AccountDeletion::create(),
        ];
    }
}
