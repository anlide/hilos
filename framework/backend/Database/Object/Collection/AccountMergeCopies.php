<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\AccountMergeCopies as EntityAccountMergeCopies;
use Hilos\Database\Object\Item\AccountMergeCopy as ObjectAccountMergeCopy;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use JsonException;

/**
 * @extends Objects<ObjectAccountMergeCopy>
 * @method ObjectAccountMergeCopy|null first()
 * @method ObjectAccountMergeCopy|null last()
 * @method ObjectAccountMergeCopy|null get(int|string $key)
 * @method ObjectAccountMergeCopy|null offsetGet(mixed $offset)
 */
class AccountMergeCopies extends Objects
{
    public const string OBJECT_CLASS = ObjectAccountMergeCopy::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAccountMergeCopies::class;
    public const string COLLECTION_KEY = HilosDbContext::accountMergeCopies;

    /**
     * @param int $assignmentId Canonical loser give assignment
     * @param string $ownerAgentId Full canonical loser agent id
     * @param AccountMergeHandoverCopy $copy Complete private transfer copy
     * @return ObjectAccountMergeCopy Newly completed immutable row
     * @throws HilosException When metadata or DB-only payload storage refuses creation
     * @throws JsonException When the copy cannot be encoded
     */
    public function record(int $assignmentId, string $ownerAgentId, AccountMergeHandoverCopy $copy): ObjectAccountMergeCopy
    {
        $entity = static::entityClass()::getEmpty();
        $entity->assignment_id = $assignmentId;
        $entity->owner_agent_id = $ownerAgentId;
        $entity->created_at = TimeHelper::nowMs();
        $record = static::OBJECT_CLASS::fromEntity($entity);
        $record->sync();
        $record->writePayloadAtBirth($copy);
        $this[$assignmentId] = $record;

        return $record;
    }
}
