<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\Auth\SecondFactor\SecondFactorResetWait;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AddressablePerson;
use Hilos\Users\SecondFactorFate;
use Hilos\Utils\Helpers\TimeHelper;
use Throwable;

/**
 * Commits the loser's complete handover and its private copy beside the nonsecret give fact.
 * SCAFFOLD: the coordinator starts giving in HIL-2000; the live merge switches in HIL-1999.
 */
final class AccountMergeGiving
{
    /**
     * Saved facts are checked before addressability or business reads, including for a folded account.
     * Planning and transfer run synchronously; only the participant opens and ends the transaction.
     *
     * @param int $assignmentId Immutable loser go/give assignment
     * @param int $userId Account represented by the current person owner
     * @return AccountOperationResult Committed give or its exact saved fact
     * @throws Throwable When admission, storage or transactional work fails, preserving the original failure
     */
    public static function run(int $assignmentId, int $userId): AccountOperationResult
    {
        $assignment = self::assignment($assignmentId, $userId);
        $saved = self::saved($assignment);
        if ($saved !== null) {
            return $saved;
        }
        AddressablePerson::require($userId);
        $operation = Hilos::$db->accountOperations->fresh($assignment->operationId);
        if ($operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ClosedStage);
        }
        $source = AccountMergePreparationResult::forAccount($operation, $userId);
        $target = AccountMergePreparationResult::forAccount($operation, $operation->survivorUserId);
        if ($source === null || !$source->isReserved() || $target === null || !$target->isReserved()) {
            throw new ValidationException('Both accounts must reserve their merge preparations before giving rows.');
        }
        $choice = $operation->mergeChoice;
        if ($choice->passwordFate === null && $source->passwordIdentityId !== null && $target->passwordIdentityId !== null) {
            throw new LogicException('Merging two accounts with a password each needs a password fate');
        }
        $identities = Hilos::$db->identities->listByUser($userId);
        $passkeys = iterator_to_array(Hilos::$db->passkeyCredentials->whereColumnIs(EntityPasskeyCredential::user_id, $userId));
        $factors = Hilos::$db->secondFactors->listByUser($userId);
        $codes = Hilos::$db->secondFactorBackupCodes->listByUser($userId);
        foreach ([&$identities, &$passkeys, &$factors, &$codes] as &$items) {
            usort($items, static fn (DbItem $left, DbItem $right): int => $left->id <=> $right->id);
        }
        unset($items);
        $password = $source->passwordIdentityId === null ? null : Hilos::$db->identities[$source->passwordIdentityId] ?? null;
        if ($source->passwordIdentityId !== null
            && ($password === null || $password->userId !== $userId || $password->type !== IdentityType::PASSWORD)) {
            throw new ValidationException('The source preparation password no longer has its captured identity.');
        }
        $demote = $password !== null && $choice->passwordFate !== null && $choice->passwordFate !== PasswordFate::LOSER;
        $deletePassword = $demote && (!$password->verified
            || Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $password->identifier) !== null);
        $identityIds = [];
        foreach ($identities as $identity) {
            if (!$deletePassword || $identity->id !== $source->passwordIdentityId) {
                $identityIds[] = $identity->id;
            }
        }
        $passkeyIds = array_map(static fn (DbItem $row): int => $row->id, $passkeys);
        $keepFactors = $choice->secondFactorFate === SecondFactorFate::BOTH;
        $factorIds = $keepFactors ? $source->secondFactorIds : [];
        $codeIds = $keepFactors ? array_map(static fn (DbItem $row): int => $row->id, $codes) : [];
        $rows = new AccountMergeTransferRows($identityIds, $passkeyIds, $factorIds, $codeIds);

        $result = AccountMergeTransfer::run($assignmentId, $rows, static function (AccountMergeTransfer $transfer) use (
            $assignment, $source, $target, $choice, $password, $demote, $deletePassword, $keepFactors,
            $identities, $passkeys, $factors, $codes, $rows,
        ): AccountOperationResultPayload {
            $wait = $keepFactors
                ? SecondFactorResetWait::of($transfer->loserUserId)->effectiveDays(SecondFactorPolicy::current(), TimeHelper::nowMs())
                : null;
            if ($demote) {
                $password->actions->demoteForMerge($transfer);
            }
            $identityCopies = [];
            foreach ($identities as $identity) {
                if (!in_array($identity->id, $rows->identities, true)) {
                    continue;
                }
                $secret = $identity->readForMerge($transfer);
                $identity->actions->transferForMerge($transfer);
                $identityCopies[] = new AccountMergeIdentityRow(
                    $identity->id, $identity->userId, $identity->type, $identity->identifier, $secret,
                    $identity->provider, $identity->verified, $identity->passwordChangedAt, $identity->providerName,
                    $identity->getObject()->valueForColumn(EntityIdentity::created_at),
                    $identity->getObject()->valueForColumn(EntityIdentity::updated_at),
                );
            }
            $passkeyCopies = [];
            foreach ($passkeys as $credential) {
                $credential->actions->transferForMerge($transfer);
                $object = $credential->getObject();
                $passkeyCopies[] = new AccountMergePasskeyRow(
                    $credential->id, $credential->identityId, $credential->userId, $credential->credentialId,
                    $object->publicKey, $object->algorithm, $object->signCount, $credential->transports,
                    $credential->aaguid, $object->userHandle, $credential->label, $credential->lastUsedAt, $credential->createdAt,
                );
            }
            $factorCopies = [];
            foreach ($factors as $factor) {
                if (!in_array($factor->id, $rows->secondFactors, true)) {
                    $factor->actions->delete();
                    continue;
                }
                $secret = $factor->readForMerge($transfer);
                $factor->actions->transferForMerge($transfer);
                $factorCopies[] = new AccountMergeSecondFactorRow(
                    $factor->id, $factor->userId, $factor->label, $secret, $factor->lastUsedStep,
                    $factor->confirmedAt, $factor->lastUsedAt, $factor->createdAt,
                );
            }
            $codeCopies = [];
            if ($keepFactors) {
                foreach ($codes as $code) {
                    $secret = $code->readForMerge($transfer);
                    $code->actions->transferForMerge($transfer);
                    $codeCopies[] = new AccountMergeBackupCodeRow($code->id, $code->userId, $secret, $code->usedAt, $code->createdAt);
                }
            } else {
                Hilos::$db->secondFactorBackupCodes->actions->deleteForUser($transfer->loserUserId);
            }
            Hilos::$db->secondFactorResets->actions->deleteForUser($transfer->loserUserId);
            Hilos::$db->secondFactorSettings->actions->deleteForUser($transfer->loserUserId);
            Hilos::$db->secondFactorTrusts->actions->deleteForUser($transfer->loserUserId);
            Hilos::$db->userMerges->actions->add($transfer->loserUserId, $transfer->survivorUserId);
            Hilos::$db->users[$transfer->loserUserId]->actions->setBlock(true);
            $copy = new AccountMergeHandoverCopy(
                $transfer->operationId, $assignment->id, $transfer->loserUserId, $transfer->survivorUserId,
                $deletePassword ? null : $source->passwordIdentityId, $target->passwordIdentityId, $wait,
                $identityCopies, $passkeyCopies, $factorCopies, $codeCopies,
            );
            Hilos::$db->accountMergeCopies->actions->record($assignment, $copy);
            $passwordKept = match ($choice->passwordFate) {
                PasswordFate::LOSER => $source->passwordIdentityId === null ? PasswordFate::NONE : PasswordFate::LOSER,
                PasswordFate::NONE => PasswordFate::NONE,
                PasswordFate::SURVIVOR => $target->passwordIdentityId === null ? PasswordFate::NONE : PasswordFate::SURVIVOR,
                null => $target->passwordIdentityId !== null ? PasswordFate::SURVIVOR
                    : ($source->passwordIdentityId !== null ? PasswordFate::LOSER : PasswordFate::NONE),
            };
            $sourceProtected = $keepFactors && $source->secondFactorIds !== [];
            $targetProtected = $target->secondFactorIds !== [];
            $secondFactorKept = match (true) {
                $sourceProtected && $targetProtected => AccountMergeGiveResult::SECOND_FACTOR_BOTH,
                $sourceProtected => AccountMergeGiveResult::SECOND_FACTOR_LOSER,
                $targetProtected => AccountMergeGiveResult::SECOND_FACTOR_SURVIVOR,
                default => AccountMergeGiveResult::SECOND_FACTOR_NONE,
            };

            return (new AccountMergeGiveResult(
                $rows->identities, $rows->passkeyCredentials, $rows->secondFactors, $rows->secondFactorBackupCodes,
                $passwordKept, $secondFactorKept,
            ))->toPayload();
        });
        AccountMergeGiveResult::of($result->payload);

        return $result;
    }

    /**
     * Reads only the durable copy and fact; a purged copy is never rebuilt from the survivor.
     *
     * @param int $assignmentId Immutable loser go/give assignment
     * @param int $userId Account represented by the current person owner
     * @return ?AccountMergeHandoverCopy Saved private copy, or null if no copy remains
     * @throws HilosException When the assignment, owner, stored fact or private copy refuses the read
     */
    public static function copy(int $assignmentId, int $userId): ?AccountMergeHandoverCopy
    {
        $assignment = self::assignment($assignmentId, $userId);
        if (self::saved($assignment) === null) {
            throw new InvalidFormatException('A merge handover copy requires its saved give fact.');
        }

        return isset(Hilos::$db->accountMergeCopies[$assignmentId])
            ? Hilos::$db->accountMergeCopies[$assignmentId]->readForMerge()
            : null;
    }

    /**
     * @param int $assignmentId Immutable assignment to validate
     * @param int $userId Person represented by this executor
     * @return AccountOperationAssignment Canonical source address, independent of the current stage
     * @throws HilosException When storage, the executor or immutable address refuses the call
     */
    private static function assignment(int $assignmentId, int $userId): AccountOperationAssignment
    {
        $executor = ExecutionContext::currentAgentId();
        if ($executor === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::NoParticipant);
        }
        $assignment = Hilos::$db->accountOperationAssignments[$assignmentId] ?? null;
        if ($assignment === null) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::UnknownAssignment);
        }
        $operation = $assignment->operation;
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->loserUserId !== $userId || $userId <= 0
            || $operation->survivorUserId === null || $operation->survivorUserId <= 0 || $operation->survivorUserId === $userId
            || $assignment->operationId !== $operation->id || $assignment->userId !== $userId
            || $assignment->stage !== AccountMergeStage::Go->value || $assignment->assignmentKey !== AccountMergeDuty::Give->value
            || !$assignment->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId))
            || $assignment->owner->agentId() !== $executor) {
            throw new AccountOperationStepRefusedException(AccountOperationStepRefusal::ForeignAssignment);
        }

        return $assignment;
    }

    /**
     * @param AccountOperationAssignment $assignment Checked immutable source address
     * @return ?AccountOperationResult Exact saved give, or null before the first attempt
     * @throws HilosException When the stored fact has a foreign owner, assignment or invalid format
     */
    private static function saved(AccountOperationAssignment $assignment): ?AccountOperationResult
    {
        $saved = Hilos::$db->accountOperationResults->byAssignment($assignment->id);
        if ($saved !== null) {
            if ($saved->assignmentId !== $assignment->id || $saved->ownerAgentId !== $assignment->owner->agentId()) {
                throw new InvalidFormatException('The saved merge give does not match its assigned owner.');
            }
            AccountMergeGiveResult::of($saved->payload);
        }

        return $saved;
    }
}
