<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\Object\Collection\AccountOperations as ObjectAccountOperations;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\HilosException;
use JsonException;

/**
 * Persistence CAS only; a business driver must prove the stage transition and terminal outcome.
 *
 * @extends DbActions<AccountOperation, ObjectAccountOperation>
 * @property-read ObjectAccountOperation $object
 * @method ObjectAccountOperations|null getObjectCollection()
 */
class AccountOperationActions extends DbActions
{
    /**
     * @param string $expectedStage Stage required in the database
     * @param string $nextStage Literal stage to store
     * @return bool Whether this statement moved the persisted stage
     * @throws HilosException When ownership, validation, storage or synchronization fails
     */
    public function advance(string $expectedStage, string $nextStage): bool
    {
        $this->ensureCanWrite();

        return $this->getObjectCollection()->advance($this->object, $expectedStage, $nextStage);
    }

    /**
     * @param string $expectedStage Stage required in the database
     * @param AccountOperationOutcome $outcome Terminal storage outcome
     * @return bool Whether this statement closed the operation
     * @throws HilosException When ownership, validation, storage or synchronization fails
     */
    public function finish(string $expectedStage, AccountOperationOutcome $outcome): bool
    {
        $this->ensureCanWrite();

        return $this->getObjectCollection()->finish($this->object, $expectedStage, $outcome);
    }

    /**
     * Keeps the safe reason an account's final was refused; a repeat for the same account replaces it.
     *
     * @param int $userId Account of the operation whose final was refused
     * @param ?string $table Table still referencing the person, or null when the refusal named none
     * @return bool Whether this statement kept the reason on the still open operation
     * @throws HilosException When ownership, validation, storage or synchronization fails
     * @throws JsonException When the table name is not valid UTF-8
     */
    public function noteFinalRefusal(int $userId, ?string $table): bool
    {
        $this->ensureCanWrite();

        return $this->getObjectCollection()->noteFinalRefusal($this->object, $userId, $table);
    }
}
