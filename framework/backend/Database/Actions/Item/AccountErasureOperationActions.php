<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Auth\AccountErasure\AccountErasureOutcome;
use Hilos\Database\Object\Item\AccountErasureOperation as ObjectAccountErasureOperation;
use Hilos\Database\Object\Collection\AccountErasureOperations as ObjectAccountErasureOperations;
use Hilos\Database\View\Item\AccountErasureOperation;
use Hilos\HilosException;

/**
 * Persistence CAS only; a business driver must prove the stage transition and terminal outcome.
 *
 * @extends DbActions<AccountErasureOperation, ObjectAccountErasureOperation>
 * @property-read ObjectAccountErasureOperation $object
 * @method ObjectAccountErasureOperations|null getObjectCollection()
 */
class AccountErasureOperationActions extends DbActions
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
     * @param AccountErasureOutcome $outcome Terminal storage outcome
     * @return bool Whether this statement closed the operation
     * @throws HilosException When ownership, validation, storage or synchronization fails
     */
    public function finish(string $expectedStage, AccountErasureOutcome $outcome): bool
    {
        $this->ensureCanWrite();

        return $this->getObjectCollection()->finish($this->object, $expectedStage, $outcome);
    }
}
