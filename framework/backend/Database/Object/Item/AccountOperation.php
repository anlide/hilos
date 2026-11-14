<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\DatabaseException;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
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
 * @extends Object_<EntityAccountOperation>
 * @property-read ?int $id
 * @property-read AccountOperationKind $kind
 * @property-read ?int $requestId
 * @property-read ?int $loserUserId
 * @property-read ?int $survivorUserId
 * @property-read int $rootUserId
 * @property-read string $circleUserIds
 * @property-read string $stage
 * @property-read int $createdAt
 * @property-read int $updatedAt
 * @property-read ?int $finishedAt
 * @property-read ?string $outcome
 */
class AccountOperation extends Object_
{
    public const string ENTITY_CLASS = EntityAccountOperation::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccountOperations::class;
    public const string id = 'id';
    public const string kind = 'kind';
    public const string requestId = 'requestId';
    public const string loserUserId = 'loserUserId';
    public const string survivorUserId = 'survivorUserId';
    public const string mergeChoice = 'mergeChoice';
    public const string rootUserId = 'rootUserId';
    public const string circleUserIds = 'circleUserIds';
    public const string stage = 'stage';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';
    public const string finishedAt = 'finishedAt';
    public const string outcome = 'outcome';
    public const string finalRefusals = 'finalRefusals';

    /** @throws ValidationException When this is not a merge or its stored choices are malformed */
    public AccountMergeChoice $mergeChoice {
        get => $this->mergeChoice();
    }

    /**
     * Safe reason of each account's refused final by user id: the table still referencing the person, or null
     * when the refusal named none; empty before the first refusal.
     *
     * @var array<int, ?string>
     * @throws ValidationException When the stored refusals are malformed
     */
    public array $finalRefusals {
        get => $this->finalRefusals();
    }

    /**
     * @param string $property Stored field name
     * @return mixed Stored value or typed operation data
     * @throws DatabaseException When the field is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::kind => AccountOperationKind::from($this->entity->kind),
            self::loserUserId => $this->entity->loser_user_id,
            self::survivorUserId => $this->entity->kind === AccountOperationKind::Merge->value ? $this->entity->root_user_id : null,
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
            self::kind => $this->entity->kind,
            self::loserUserId => $this->entity->loser_user_id,
            self::mergeChoice => $this->entity->merge_choice,
            self::requestId => $this->entity->request_id,
            self::rootUserId => $this->entity->root_user_id,
            self::circleUserIds => $this->entity->circle_user_ids,
            self::stage => $this->entity->stage,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            self::finishedAt => $this->entity->finished_at,
            self::outcome => $this->entity->outcome,
            self::finalRefusals => $this->entity->final_refusals,
        ];
    }

    /**
     * @return AccountOperationCircle Checked fixed account order
     * @throws ValidationException When stored circle data is malformed
     */
    public function circle(): AccountOperationCircle
    {
        $ids = JsonHelper::tryDecode($this->entity->circle_user_ids);
        if ($ids === null) {
            throw new ValidationException('The stored account operation circle is invalid.');
        }

        return AccountOperationCircle::fromArray($ids, $this->entity->root_user_id);
    }

    /**
     * @return AccountMergeChoice Immutable merge choices
     * @throws ValidationException When this is not a merge or its stored choices are malformed
     */
    public function mergeChoice(): AccountMergeChoice
    {
        if ($this->entity->kind !== AccountOperationKind::Merge->value || $this->entity->merge_choice === null) {
            throw new ValidationException('This account operation has no merge choices.');
        }

        return AccountMergeChoice::fromJson($this->entity->merge_choice);
    }

    /**
     * @param string $stage Literal stage key
     * @throws ValidationException When the key is empty or exceeds the storage limit
     */
    public static function validateStage(string $stage): void
    {
        if ($stage === '' || mb_strlen($stage) > EntityAccountOperation::STAGE_MAX_CHARACTERS) {
            throw new ValidationException('The account operation stage must be nonempty and fit its storage limit.');
        }
    }

    /**
     * Reconciles an already issued object without issuing a second UPDATE.
     *
     * @param EntityAccountOperation $stored Fresh database row
     * @param bool $announce Whether this process performed the conditional write
     * @throws DatabaseException When the database binding or announcement fails
     * @throws InvalidArgumentException When the sync signal cannot be named
     * @throws SourceChangeSubscriberException When a source subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     */
    public function acceptStored(EntityAccountOperation $stored, bool $announce = false): void
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

    /**
     * @return array<int, ?string> Safe reason of each account's refused final by user id; empty before the first refusal
     * @throws ValidationException When the stored refusals are not an object of account ids to a table name or null
     */
    private function finalRefusals(): array
    {
        if ($this->entity->final_refusals === null) {
            return [];
        }
        $refusals = JsonHelper::tryDecode($this->entity->final_refusals);
        if ($refusals === null
            || array_filter(
                $refusals,
                static fn (mixed $table, int|string $userId): bool => !is_int($userId) || $userId < 1
                    || ($table !== null && !is_string($table)),
                ARRAY_FILTER_USE_BOTH,
            ) !== []) {
            throw new ValidationException('The stored account operation final refusals are invalid.');
        }

        return $refusals;
    }
}
