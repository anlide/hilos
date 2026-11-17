<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Closure;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
use Hilos\Database\Entity\Item\SecondFactor as EntitySecondFactor;
use Hilos\Database\Entity\Item\SecondFactorBackupCode as EntitySecondFactorBackupCode;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\SecondFactorFate;
use JsonException;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * An attempt-local right to give selected rows, with no ambient permission to write either account.
 * SCAFFOLD: the merge hand-over consumer follows in HIL-1992; the production merge remains unchanged.
 */
final class AccountMergeTransfer
{
    private const array ROW_ENTITIES = [
        HilosDbContext::identities => EntityIdentity::class,
        HilosDbContext::passkeyCredentials => EntityPasskeyCredential::class,
        HilosDbContext::secondFactors => EntitySecondFactor::class,
        HilosDbContext::secondFactorBackupCodes => EntitySecondFactorBackupCode::class,
    ];

    private bool $active = true;

    /** @var WeakMap<Object_, string> Captured collection and row id, without retaining row data */
    private readonly WeakMap $members;

    /** @var array<string, WeakReference<Object_>> Attempt-local obligations survive even if a captured Object is released */
    private array $fencedMembers = [];

    /** @var array<string, array<int, AccountMergeRowRevision>> Attempt-local stamps; the saved give result is the durable manifest */
    private array $admissions = [];

    /** @var WeakMap<Object_, int> Own preparation password anchor, independent of the transfer manifest */
    private readonly WeakMap $passwordAnchors;

    /** @var array<int, int> Selected credential id to its captured identity id */
    private array $passkeyAnchors = [];

    /**
     * @param int $assignmentId Checked give assignment
     * @param int $operationId Checked merge operation
     * @param int $loserUserId Source account from the operation
     * @param int $survivorUserId Target account from the operation
     * @param string $executorId Canonical source owner executing this attempt
     */
    private function __construct(
        public readonly int $assignmentId,
        public readonly int $operationId,
        public readonly int $loserUserId,
        public readonly int $survivorUserId,
        private readonly string $executorId,
    ) {
        $this->members = new WeakMap();
        $this->passwordAnchors = new WeakMap();
    }

    /** The transaction hooks and finally close the one issued instance. */
    private function __clone(): void
    {
    }

    /**
     * The callback joins the participant transaction: it must neither end it nor yield the event loop.
     * Saved results bypass admission and the callback, including after the operation has finished.
     *
     * @param int $assignmentId Loser's immutable go/give assignment
     * @param AccountMergeTransferRows $rows Explicit selection of existing source rows
     * @param Closure(self): AccountOperationResultPayload $work Synchronous hand-over work
     * @return AccountOperationResult Committed result or the exact saved result
     * @throws AccountOperationStepRefusedException When the assignment, executor or current stage refuses the step
     * @throws ValidationException When preparation metadata is absent, refused or malformed
     * @throws WriteNotAllowedException When the manifest or live source grant refuses a row
     * @throws Throwable When storage or consumer work fails, preserving the original failure
     */
    public static function run(int $assignmentId, AccountMergeTransferRows $rows, Closure $work): AccountOperationResult
    {
        $executorId = ExecutionContext::currentAgentId();
        if ($executorId === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::NoParticipant);
        }
        $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
        if ($assignment === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::UnknownAssignment);
        }
        $operation = $assignment->operation;
        self::guardAssignment($assignment, $operation, $executorId);
        $operationId = $operation->id;
        $loserUserId = $operation->loserUserId;
        $survivorUserId = $operation->survivorUserId;
        // The participant's stage check must see current metadata rather than a delayed mirror.
        $fresh = Hilos::$db->accountOperations->fresh($operationId);
        self::guardAssignment($assignment, $fresh, $executorId);

        return AccountOperationParticipantStep::run($assignmentId, static function () use (
            $assignment, $operationId, $loserUserId, $survivorUserId, $executorId, $rows, $work,
        ): AccountOperationResultPayload {
            // This current read holds the existing metadata shared lock until the participant's result commits.
            $operation = Hilos::$db->accountOperations->fresh($operationId);
            self::guardAssignment($assignment, $operation, $executorId);
            if ($operation->loserUserId !== $loserUserId || $operation->survivorUserId !== $survivorUserId) {
                throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
            }
            if ($operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
                throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ClosedStage);
            }
            self::preparation($operation, AccountMergeDuty::PrepareSurvivor, $survivorUserId);
            $source = self::preparation($operation, AccountMergeDuty::PrepareLoser, $loserUserId);
            $transfer = new self($assignment->id, $operationId, $loserUserId, $survivorUserId, $executorId);
            $connectionIndex = Database::getDatabase(DatabaseNames::PRIMARY)->connectionIndex;
            $close = static function () use ($transfer): void {
                $transfer->active = false;
            };
            Database::afterCommit($close, $connectionIndex);
            Database::onRollback($close, $connectionIndex);
            try {
                $transfer->capture($rows, $operation->mergeChoice, $source);

                $payload = $work($transfer);
                foreach ($transfer->fencedMembers as $reference) {
                    $item = $reference->get();
                    if ($item === null || !$item->isRelated() || $item->hasChanges()
                        || $item->storedSetTop() !== (string)$survivorUserId) {
                        throw new WriteNotAllowedException('Merge fence metadata must commit with its captured business transfer.');
                    }
                }

                return $payload;
            } finally {
                $transfer->active = false;
                $transfer->fencedMembers = [];
                $transfer->admissions = [];
            }
        });
    }

    /**
     * Checks stored source membership, allowing a proposed user_id diff to be judged separately by the save door.
     * A credential follows its already transferred identity, never an unsaved edit to that identity.
     *
     * @param string $collection Collection of the proposed row
     * @param Object_ $item Captured existing row
     * @throws WriteNotAllowedException When the right, executor, live grant, membership or anchor no longer holds
     * @throws HilosException When a stored coordinate or selected identity cannot be read
     */
    public function guardRow(string $collection, Object_ $item): void
    {
        if (!$this->active || ExecutionContext::currentAgentId() !== $this->executorId
            || !isset(self::ROW_ENTITIES[$collection])) {
            throw new WriteNotAllowedException('This account merge transfer right is not active for this executor or collection.');
        }
        TruthSourceRegistry::checkCanGiveSet($collection, (string)$this->loserUserId);
        $entity = self::ROW_ENTITIES[$collection];
        if (!$item->isRelated() || !isset($this->members[$item])
            || $this->members[$item] !== $collection . ':' . $item->valueForColumn($entity::id)
            || $item->storedSetTop() !== (string)$this->loserUserId) {
            throw new WriteNotAllowedException('This row is not a captured member of the merge source set.');
        }
        if ($collection === HilosDbContext::passkeyCredentials) {
            $anchor = $this->passkeyAnchors[$item->valueForColumn(EntityPasskeyCredential::id)];
            $identity = Hilos::$db->identities[$anchor] ?? null;
            if ($item->valueForColumn(EntityPasskeyCredential::identity_id) !== $anchor || $identity === null
                || !isset($this->members[$identity->getObject()])
                || $this->members[$identity->getObject()] !== HilosDbContext::identities . ':' . $anchor
                || !$identity->getObject()->isRelated() || $identity->getObject()->hasChanges()
                || $identity->getObject()->storedSetTop() !== (string)$this->survivorUserId) {
                throw new WriteNotAllowedException('A passkey must follow its captured identity into the merge target set.');
            }
        }
    }

    /**
     * Records a commit obligation for a captured row, without retaining its business data.
     *
     * @internal The metadata guard uses the same live source capability as the business save
     * @param string $collection Captured business collection
     * @param int $rowId Captured positive row id
     * @return Object_ Exact captured source Object
     * @throws HilosException When the capability, source grant, stored membership or passkey anchor refuses the row
     */
    public function guardFenceRow(string $collection, int $rowId): Object_
    {
        foreach ($this->members as $item => $key) {
            if ($key === $collection . ':' . $rowId) {
                $this->guardRow($collection, $item);
                $this->fencedMembers[$key] = WeakReference::create($item);

                return $item;
            }
        }

        throw new WriteNotAllowedException('Merge fence metadata requires its captured business row.');
    }

    /**
     * Joins the scoped business save before its SQL; the outstanding obligation prevents a metadata-only commit.
     *
     * @internal Only the guarded syncForMerge save stamps a captured row
     * @param string $collection Captured business collection
     * @param Object_ $item Exact business Object being transferred
     * @throws HilosException When the capture, metadata grant, membership or optimistic comparison refuses the stamp
     */
    public function stampRow(string $collection, Object_ $item): void
    {
        $this->guardRow($collection, $item);
        $rowId = $item->valueForColumn(self::ROW_ENTITIES[$collection]::id);
        if (isset($this->admissions[$collection][$rowId])) {
            throw new WriteNotAllowedException('A merge transfer can stamp its captured row only once.');
        }
        $fence = Hilos::$db->accountMergeFences[$collection . ':' . $rowId] ?? null;
        $revision = $fence === null
            ? Hilos::$db->accountMergeFences->actions->birthForMerge($this, $collection, $rowId)->revision
            : $fence->actions->giveForMerge($this)->revision;
        $this->admissions[$collection][$rowId] = new AccountMergeRowRevision($rowId, $revision);
    }

    /**
     * @return AccountMergeAdmissionManifest Original revisions of completed writes in this active attempt
     * @throws HilosException When the attempt is closed or a stamped business transfer has not completed
     */
    public function admissionManifest(): AccountMergeAdmissionManifest
    {
        if (!$this->active || ExecutionContext::currentAgentId() !== $this->executorId) {
            throw new WriteNotAllowedException('A merge admission manifest belongs to its active source attempt.');
        }
        foreach ($this->fencedMembers as $reference) {
            $item = $reference->get();
            if ($item === null || !$item->isRelated() || $item->hasChanges()
                || $item->storedSetTop() !== (string)$this->survivorUserId) {
                throw new WriteNotAllowedException('Merge admission requires completed captured business transfers.');
            }
        }
        $lists = [];
        foreach (array_keys(self::ROW_ENTITIES) as $collection) {
            $stamps = $this->admissions[$collection] ?? [];
            ksort($stamps, SORT_NUMERIC);
            $lists[] = array_values($stamps);
        }

        return new AccountMergeAdmissionManifest(...$lists);
    }

    /**
     * The preparation password may be deleted instead of transferred, so it need not belong to the manifest.
     *
     * @param Object_ $item Own clean password captured at admission
     * @throws WriteNotAllowedException When the live source right, executor or preparation anchor no longer holds
     * @throws HilosException When the stored row coordinates cannot be read
     */
    public function guardPassword(Object_ $item): void
    {
        if (!$this->active || ExecutionContext::currentAgentId() !== $this->executorId) {
            throw new WriteNotAllowedException('This account merge password right is not active for this executor.');
        }
        TruthSourceRegistry::checkCanGiveSet(HilosDbContext::identities, (string)$this->loserUserId);
        if (!isset($this->passwordAnchors[$item]) || !$item->isRelated() || $item->hasChanges()
            || $item->valueForColumn(EntityIdentity::id) !== $this->passwordAnchors[$item]
            || $item->valueForColumn(EntityIdentity::type) !== IdentityType::PASSWORD
            || $item->valueForColumn(EntityIdentity::user_id) !== $this->loserUserId
            || $item->storedSetTop() !== (string)$this->loserUserId) {
            throw new WriteNotAllowedException('Password demotion requires the captured source preparation password.');
        }
    }

    /**
     * @param AccountOperationAssignment $assignment Immutable participant address
     * @param ?AccountOperation $operation Merge named by that assignment
     * @param string $executorId Current participant
     * @throws AccountOperationStepRefusedException When this is not the canonical loser's merge give
     * @throws HilosException When the canonical owner address cannot be formed
     */
    private static function guardAssignment(AccountOperationAssignment $assignment, ?AccountOperation $operation, string $executorId): void
    {
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->loserUserId === null || $operation->loserUserId <= 0
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0
            || $operation->loserUserId === $operation->survivorUserId
            || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || $assignment->userId !== $operation->loserUserId
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId))
            || $assignment->owner->agentId() !== $executorId) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
        }
    }

    /**
     * @param AccountOperation $operation Admitted merge under its metadata lock
     * @param AccountMergeDuty $duty Exact preparation side
     * @param int $userId Account expected on that side
     * @return AccountMergePreparationResult Checked reserved snapshot
     * @throws ValidationException When that exact account and owner have not reserved their preparation
     * @throws HilosException When preparation storage or metadata cannot be read
     * @throws JsonException When the saved payload cannot be decoded
     */
    private static function preparation(AccountOperation $operation, AccountMergeDuty $duty, int $userId): AccountMergePreparationResult
    {
        $assignment = Hilos::$db->accountOperationAssignments->byIdentity($operation->id, AccountMergeStage::Preparing->value, $duty->value);
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        if ($assignment === null || $assignment->operationId !== $operation->id
            || $assignment->stage !== AccountMergeStage::Preparing->value || $assignment->assignmentKey !== $duty->value
            || $assignment->userId !== $userId || !$assignment->owner->equals($owner)) {
            throw new ValidationException('The merge requires both accounts to reserve their exact preparation assignments.');
        }
        $result = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($result === null || $result->assignmentId !== $assignment->id || $result->ownerAgentId !== $owner->agentId()) {
            throw new ValidationException('The merge requires a preparation result from each assigned account owner.');
        }
        $preparation = AccountMergePreparationResult::of($result->payload);
        if (!$preparation->isReserved()) {
            throw new ValidationException('Both accounts must reserve their merge preparations before giving rows.');
        }

        return $preparation;
    }

    /**
     * @param AccountMergeTransferRows $rows Explicit existing source selection
     * @param AccountMergeChoice $choice Immutable protection fate
     * @param AccountMergePreparationResult $source Source's confirmed-factor snapshot
     * @throws WriteNotAllowedException When a selected row, anchor, protection fate or source grant refuses admission
     * @throws HilosException When a selected row's stored coordinates cannot be read
     */
    private function capture(AccountMergeTransferRows $rows, AccountMergeChoice $choice, AccountMergePreparationResult $source): void
    {
        if ($source->passwordIdentityId !== null && $choice->passwordFate !== null && $choice->passwordFate !== PasswordFate::LOSER) {
            $password = Hilos::$db->identities[$source->passwordIdentityId] ?? null;
            if ($password !== null) {
                $this->passwordAnchors[$password->getObject()] = $source->passwordIdentityId;
            }
        }
        if (($rows->secondFactors !== [] || $rows->secondFactorBackupCodes !== [])
            && $choice->secondFactorFate !== SecondFactorFate::BOTH) {
            throw new WriteNotAllowedException('Second-factor rows may only be given when both protections are preserved.');
        }
        if ($rows->secondFactorBackupCodes !== [] && $source->secondFactorIds === []) {
            throw new WriteNotAllowedException('Backup codes require a confirmed source preparation snapshot.');
        }
        foreach ([
            HilosDbContext::identities => $rows->identities,
            HilosDbContext::passkeyCredentials => $rows->passkeyCredentials,
            HilosDbContext::secondFactors => $rows->secondFactors,
            HilosDbContext::secondFactorBackupCodes => $rows->secondFactorBackupCodes,
        ] as $collection => $ids) {
            foreach ($ids as $id) {
                TruthSourceRegistry::checkCanGiveSet($collection, (string)$this->loserUserId);
                $item = Hilos::$db->{$collection}[$id] ?? null;
                $entity = self::ROW_ENTITIES[$collection];
                if ($item === null || !$item->getObject()->isRelated() || $item->getObject()->hasChanges()
                    || $item->getObject()->valueForColumn($entity::id) !== $id
                    || $item->getObject()->valueForColumn($entity::user_id) !== $this->loserUserId
                    || $item->getObject()->storedSetTop() !== (string)$this->loserUserId) {
                    throw new WriteNotAllowedException('Transfer admission requires a clean existing row of the source set.');
                }
                if ($collection === HilosDbContext::passkeyCredentials) {
                    $anchor = $item->getObject()->valueForColumn(EntityPasskeyCredential::identity_id);
                    if (!in_array($anchor, $rows->identities, true)) {
                        throw new WriteNotAllowedException('A selected passkey requires its source identity in the same manifest.');
                    }
                    $this->passkeyAnchors[$id] = $anchor;
                }
                if ($collection === HilosDbContext::secondFactors
                    && (!in_array($id, $source->secondFactorIds, true)
                        || $item->getObject()->valueForColumn(EntitySecondFactor::confirmed_at) === null)) {
                    throw new WriteNotAllowedException('A selected factor must be confirmed in the source preparation snapshot.');
                }
                $this->members[$item->getObject()] = $collection . ':' . $id;
            }
        }
    }
}
