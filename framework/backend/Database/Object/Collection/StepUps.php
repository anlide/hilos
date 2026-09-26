<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\StepUps as EntityStepUps;
use Hilos\Database\Entity\Item\StepUp as EntityStepUp;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\StepUp as ObjectStepUp;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * StepUps object collection - operation confirmations for every person (HIL-495).
 *
 * @extends Objects<ObjectStepUp>
 * @method ObjectStepUp|null current()
 * @method ObjectStepUp|null first()
 * @method ObjectStepUp|null last()
 * @method ObjectStepUp|null get(int|string $key)
 * @method ObjectStepUp|null offsetGet(mixed $offset)
 */
final class StepUps extends Objects
{
    public const string OBJECT_CLASS = ObjectStepUp::class;
    public const string ENTITY_COLLECTION_CLASS = EntityStepUps::class;
    public const string COLLECTION_KEY = HilosDbContext::stepUps;

    /**
     * Creates or extends one browser's confirmation of one operation for a person.
     *
     * @param string $tokenHash Hash of the browser session token
     * @param int $userId Person confirming the operation
     * @param string $operation Declared operation key
     * @param string $until Moment the confirmation expires (SQL datetime)
     * @throws DatabaseException When the lookup or write fails
     * @throws InvalidArgumentException When the entity query or queued DB-sync signal is invalid
     * @throws CreateNotAllowedException When no truth source may add a confirmation row
     * @throws WriteNotAllowedException When no truth source may update a confirmation row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If an inserted row has no primary key
     */
    public function confirm(string $tokenHash, int $userId, string $operation, string $until): void
    {
        $stepUp = $this->find($tokenHash, $userId, $operation);
        $isNew = $stepUp === null;
        if ($stepUp === null) {
            $stepUp = ObjectStepUp::create();
            $stepUp->sessionTokenHash = $tokenHash;
            $stepUp->userId = $userId;
            $stepUp->operation = $operation;
            $stepUp->createdAt = TimeHelper::getSqlDateTime();
        }

        $stepUp->confirmedUntil = $until;
        $stepUp->sync();

        if ($isNew && $stepUp->id !== null) {
            $this[$stepUp->id] = $stepUp;
        }
    }

    /**
     * @param string $tokenHash Hash of the browser session token
     * @param int $userId Person asking to enter the operation
     * @param string $operation Declared operation key
     * @return bool Whether the matching confirmation has not expired
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function isConfirmed(string $tokenHash, int $userId, string $operation): bool
    {
        $stepUp = $this->find($tokenHash, $userId, $operation);

        return $stepUp !== null && $stepUp->confirmedUntil > TimeHelper::getSqlDateTime();
    }

    /**
     * Deletes a person's expired confirmations.
     *
     * @param int $userId Person whose expired confirmations are removed
     * @throws DatabaseException When the lookup or delete fails
     * @throws InvalidArgumentException When the entity query or queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source may remove the row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteExpiredForUser(int $userId): void
    {
        $where = '`' . EntityStepUp::user_id . '` = ? AND `' . EntityStepUp::confirmed_until . '` <= ?';
        foreach (EntityStepUp::get($where, [$userId, TimeHelper::getSqlDateTime()]) as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, ObjectStepUp::fromEntity($entity));
            }
            $this->objects[$id]->delete();
            unset($this[$id]);
        }
    }

    /**
     * Deletes every operation confirmation of a person - the account is being erased (HIL-302).
     *
     * Each row leaves through its object so a delete announcement reaches every reader.
     * A person with none is not an error.
     *
     * @param int $userId Person whose rows to delete
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        foreach (EntityStepUp::get([EntityStepUp::user_id => $userId]) as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, ObjectStepUp::fromEntity($entity));
            }
            $this->objects[$id]->delete();
            unset($this[$id]);
        }
    }

    /**
     * @param string $tokenHash Hash of the browser session token
     * @param int $userId Person
     * @param string $operation Declared operation key
     * @return ?ObjectStepUp Matching confirmation row, or null when none exists
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    private function find(string $tokenHash, int $userId, string $operation): ?ObjectStepUp
    {
        $entity = EntityStepUp::get([
            EntityStepUp::session_token_hash => $tokenHash,
            EntityStepUp::user_id => $userId,
            EntityStepUp::operation => $operation,
        ])->first();
        if ($entity === null || $entity->id === null) {
            return null;
        }
        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, ObjectStepUp::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }
}
