<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergePreparationResult;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountMergeTransfer;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Item\Identity;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use JsonException;

/**
 * Metadata writes for one persisted sign-in method.
 *
 * @extends DbActions<Identity, ObjectIdentity>
 * @property-read ObjectIdentity $object
 */
class IdentityActions extends DbActions
{
    /**
     * Gives this selected row to the survivor of the admitted merge.
     *
     * @param AccountMergeTransfer $transfer Active give capability
     * @throws HilosException When the row, capability or scoped save refuses the transfer
     */
    public function transferForMerge(AccountMergeTransfer $transfer): void
    {
        $this->transferSelectedForMerge($transfer);
    }

    /**
     * Demotes the source preparation password before its separate transfer, or deletes it under the existing rules.
     * SCAFFOLD: called by the prepared give (HIL-1992); no production caller until the merge switch (HIL-1999).
     *
     * @param AccountMergeTransfer $transfer Active give capability
     * @throws HilosException When the anchor, ordinary own write or password demotion refuses the action
     */
    public function demoteForMerge(AccountMergeTransfer $transfer): void
    {
        $transfer->guardPassword($this->object);
        $this->ensureCanWrite(TruthSourceOperation::Update);
        Hilos::$db->identities->getObjectCollection()->demotePasswordToMagicLink(
            $this->object, $transfer->loserUserId, $transfer->survivorUserId,
        );
    }

    /**
     * Uses the reserved own password anchor, with ordinary survivor rights and no source transfer capability.
     *
     * @param int $operationId Current open merge
     * @throws HilosException When the caller, source fact, own reservation, anchor or ordinary write refuses demotion
     * @throws JsonException When the saved own preparation cannot be decoded
     */
    public function demoteOwnForMerge(int $operationId): void
    {
        $operation = Hilos::$db->accountOperations->fresh($operationId);
        $userId = $this->object->userId;
        $owner = new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId);
        if ($operation === null || $operation->kind !== AccountOperationKind::Merge
            || $operation->survivorUserId !== $userId || $userId <= 0
            || $operation->loserUserId === null || $operation->loserUserId <= 0 || $operation->loserUserId === $userId
            || $operation->finishedAt !== null || $operation->stage !== AccountMergeStage::Go->value
            || ExecutionContext::currentAgentId() !== $owner->agentId()
            || !in_array($operation->mergeChoice->passwordFate, [PasswordFate::LOSER, PasswordFate::NONE], true)) {
            throw new ValidationException('The password demotion requires its current receiving account.');
        }
        $preparation = Hilos::$db->accountOperationAssignments->byIdentity(
            $operationId, AccountMergeStage::Preparing->value, AccountMergeDuty::PrepareSurvivor->value,
        );
        $give = Hilos::$db->accountOperationAssignments->byIdentity(
            $operationId, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
        );
        if ($preparation === null || $preparation->operationId !== $operationId || $preparation->userId !== $userId
            || $preparation->stage !== AccountMergeStage::Preparing->value
            || $preparation->assignmentKey !== AccountMergeDuty::PrepareSurvivor->value
            || !$preparation->owner->equals($owner)
            || !$preparation->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))
            || $give === null || $give->operationId !== $operationId || $give->userId !== $operation->loserUserId
            || $give->stage !== AccountMergeStage::Go->value || $give->assignmentKey !== AccountMergeDuty::Give->value
            || !$give->payload->equals(AccountOperationAssignmentPayload::fromJson('{}'))
            || !$give->owner->equals(new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$operation->loserUserId))) {
            throw new ValidationException('The password demotion requires its reserved preparation and source assignment.');
        }
        $prepared = Hilos::$db->accountOperationResults->byAssignment($preparation->id);
        $given = Hilos::$db->accountOperationResults->byAssignment($give->id);
        if ($prepared === null || $prepared->assignmentId !== $preparation->id || $prepared->ownerAgentId !== $owner->agentId()
            || $given === null || $given->assignmentId !== $give->id || $given->ownerAgentId !== $give->owner->agentId()) {
            throw new ValidationException('The password demotion requires its saved preparation and source result.');
        }
        AccountMergeGiveResult::of($given->payload);
        $anchor = AccountMergePreparationResult::of($prepared->payload);
        if (!$anchor->isReserved() || $anchor->passwordIdentityId === null || $this->object->id !== $anchor->passwordIdentityId
            || $this->object->type !== IdentityType::PASSWORD) {
            throw new ValidationException('The password no longer has its reserved identity.');
        }
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        Hilos::$db->identities->getObjectCollection()->demotePasswordToMagicLink(
            $this->object, $operation->loserUserId, $operation->survivorUserId,
        );
    }

    /**
     * Records an actual password change; rehashing alone does not call this action.
     *
     * @throws ItemNotFoundForUpdateException When the identity has no persisted id
     * @throws ValidationException When the identity is not a password method
     * @throws HilosException On database, truth-source or sync failure
     */
    public function recordPasswordChange(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('The sign-in method no longer exists.');
        }
        if ($this->object->type !== IdentityType::PASSWORD) {
            throw new ValidationException('A password change requires a password sign-in method.');
        }

        $this->object->passwordChangedAt = TimeHelper::nowMs();
        $this->object->sync();
    }

    /**
     * Keeps the last usable provider name; absent or blank input never erases it.
     *
     * @param ?string $providerName Personal name supplied by the OAuth provider
     * @throws ItemNotFoundForUpdateException When the identity has no persisted id
     * @throws ValidationException When the identity is not an OAuth method
     * @throws HilosException On database, truth-source or sync failure
     */
    public function rememberProviderName(?string $providerName): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('The sign-in method no longer exists.');
        }
        if ($this->object->type !== IdentityType::OAUTH) {
            throw new ValidationException('A provider name requires an OAuth sign-in method.');
        }
        if ($providerName === null) {
            return;
        }

        $providerName = trim($providerName);
        if ($providerName === '' || $providerName === $this->object->providerName) {
            return;
        }

        $this->object->providerName = $providerName;
        $this->object->sync();
    }
}
