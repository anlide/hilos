<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Entity\Collection\AccountDeletions as EntityAccountDeletions;
use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\Object\Objects;

/**
 * Negative sample of what the rule is obliged to let through, in the very namespace where
 * it judges: an open class whose rows are built through the link constants, a read of a
 * column or table constant off the framework Entity, a count that builds no row, and the
 * judged names worn by something else — a helper of this class, a class outside the
 * chain, a string, a comment. A single reported line here means the rule has grown wider
 * than the document it enforces. The `final` a demo's chain may carry is what
 * ObjectStoreMutate next door stands for: the same shape in another namespace, and silent.
 *
 * @extends Objects<ObjectAccountDeletion>
 */
class OrmChainLookAlikes extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountDeletion::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountDeletions::class;

    /**
     * @param int $userId Person whose rows are read
     * @return array<int, mixed> Rows built the way inheritance.md asks
     */
    public function throughTheLinks(int $userId): array
    {
        $entities = static::entityClass()::get([EntityAccountDeletion::user_id => $userId]);
        $objectClass = static::OBJECT_CLASS;

        return [
            static::OBJECT_CLASS::create(),
            static::OBJECT_CLASS::fromEntity(static::entityClass()::getEmpty()),
            $objectClass::create(),
            count($entities),
            EntityAccountDeletion::_table,
            EntityAccountDeletion::countUpTo(1, [EntityAccountDeletion::user_id => $userId]) > 0,
        ];
    }

    /**
     * @return array<int, mixed> Spellings that only look like a row built by name
     */
    public function lookAlikes(): array
    {
        // ObjectAccountDeletion::create() written in a comment is a mention, not a call
        return [
            'EntityAccountDeletion::get([])',
            self::create(),
            new InvalidArgumentException('not a chain class'),
        ];
    }

    /**
     * @return int Stand-in for a helper wearing a factory's name on this class
     */
    private static function create(): int
    {
        return 0;
    }
}
