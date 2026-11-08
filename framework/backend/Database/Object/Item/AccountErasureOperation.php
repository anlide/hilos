<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Entity\Item\AccountErasureOperation as EntityAccountErasureOperation;
use Hilos\Database\Object\Collection\AccountErasureOperations as ObjectAccountErasureOperations;
use Hilos\Database\DatabaseException;
use Hilos\Auth\AccountErasure\AccountErasureCircle;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\Database;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Utils\Helpers\JsonHelper;

/**
 * @extends Object_<EntityAccountErasureOperation>
 * @property-read ?int $id
 * @property-read int $requestId
 * @property-read int $rootUserId
 * @property-read string $circleUserIds
 * @property-read string $stage
 * @property-read int $createdAt
 * @property-read int $updatedAt
 * @property-read ?int $finishedAt
 * @property-read ?string $outcome
 */
class AccountErasureOperation extends Object_
{
    public const string ENTITY_CLASS = EntityAccountErasureOperation::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountErasureOperations::class;
    public const string id = 'id';
    public const string requestId = 'requestId';
    public const string rootUserId = 'rootUserId';
    public const string circleUserIds = 'circleUserIds';
    public const string stage = 'stage';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';
    public const string finishedAt = 'finishedAt';
    public const string outcome = 'outcome';

    /**
     * @param string $property Stored field name
     * @return mixed Scalar stored value
     * @throws DatabaseException When the field is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::requestId => $this->entity->request_id,
            self::rootUserId => $this->entity->root_user_id,
            self::circleUserIds => $this->entity->circle_user_ids,
            self::stage => $this->entity->stage,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            self::finishedAt => $this->entity->finished_at,
            self::outcome => $this->entity->outcome,
            default => parent::__get($property),
        };
    }

    /** @return array<string, mixed> Stored fields with object keys */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::requestId => $this->entity->request_id,
            self::rootUserId => $this->entity->root_user_id,
            self::circleUserIds => $this->entity->circle_user_ids,
            self::stage => $this->entity->stage,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            self::finishedAt => $this->entity->finished_at,
            self::outcome => $this->entity->outcome,
        ];
    }

    /**
     * @return AccountErasureCircle Checked fixed account order
     * @throws ValidationException When stored circle data is malformed
     */
    public function circle(): AccountErasureCircle
    {
        $ids = JsonHelper::tryDecode($this->entity->circle_user_ids);
        if ($ids === null) {
            throw new ValidationException('The stored erasure circle is invalid.');
        }

        return AccountErasureCircle::fromArray($ids, $this->entity->root_user_id);
    }

    /**
     * @param string $stage Literal stage key
     * @throws ValidationException When the key is empty or exceeds the storage limit
     */
    public static function validateStage(string $stage): void
    {
        if ($stage === '' || mb_strlen($stage) > EntityAccountErasureOperation::STAGE_MAX_CHARACTERS) {
            throw new ValidationException('The erasure stage must be nonempty and fit its storage limit.');
        }
    }

    /**
     * Reconciles an already issued object without issuing a second UPDATE.
     *
     * @param EntityAccountErasureOperation $stored Fresh database row
     * @param bool $announce Whether this process performed the conditional write
     * @throws DatabaseException When the database binding or announcement fails
     * @throws InvalidArgumentException When the sync signal cannot be named
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     */
    public function acceptStored(EntityAccountErasureOperation $stored, bool $announce = false): void
    {
        $before = $this->entitySync->toArray();
        $after = $stored->toArray();
        $diff = [];
        $previous = [];
        foreach ($after as $column => $value) {
            if ($value !== $before[$column]) {
                $diff[$column] = $value;
                $previous[$column] = $before[$column];
            }
        }
        if ($diff === []) {
            return;
        }

        $index = $stored::getDatabase()->connectionIndex;
        Database::onRollback(function () use ($before, $diff, $previous): void {
            $this->restoreSaved($before, related: true);
            SourceChangeBus::publishToMirrors(SourceChange::dbUpdated(
                static::getCollectionKey(),
                $this->getIdString(),
                $previous,
                ExecutionContext::currentAcceptKey(),
                ExecutionContext::currentRequestId(),
                previous: $diff,
            ));
        }, index: $index);
        $this->restoreSaved($after, related: true);
        if ($announce) {
            $this->broadcastDbSyncAfterSync(false, $diff, $previous);
        }
    }
}
