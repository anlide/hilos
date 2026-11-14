<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosProfileNameChanges as StateHilosProfileNameChanges;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\View\Collection\HilosProfileNameChanges;
use Hilos\Runtime\View\Item\HilosProfileNameChange;
use Hilos\HilosException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Throwable;

/**
 * Creates attempts; all subsequent writes belong to the addressed item's actions.
 *
 * @extends RtActions<HilosProfileNameChange, HilosProfileNameChanges, StateHilosProfileNameChanges>
 * @property-read StateHilosProfileNameChanges $stateCollection
 */
final class HilosProfileNameChangesActions extends RtActions
{
    /**
     * Replaces the prior attempt with a new checking row. The holder admits the attempt first.
     * Removal precedes creation so runtime replicas replace the same session key as well.
     *
     * The two are one compound and stand or go back together: they run under the caller's
     * transaction, or under one of their own when the caller has none, so a creation the FIFO
     * refuses takes the removal back with its reservation (rt-state.md, "A compound write runs
     * under one transaction").
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param int $userId Person the attempt belongs to
     * @param string $requestId Fresh secure 32-hex attempt identifier
     * @param string $originAcceptKey Connection that submitted the attempt
     * @param string $name Trimmed requested name
     * @param int $startedAt Attempt start in epoch milliseconds
     * @param ?int $deadlineAt Fixed checking deadline in epoch milliseconds, or null
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws HilosException Whatever preparing the RT write's immutable operation raises
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room for the compound; nothing stays written
     * @throws RtActionsStateCollectionNullException When the state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the collection
     * @throws SourceChangeSubscriberException When a membership subscriber fails
     * @throws NestedTransactionRefusedException When the connection holds only a failed transaction its caller has not closed
     * @throws TransactionNotOpenException When the compound's own transaction was closed under it
     * @throws AnnouncementFailedException When an announcement its commit released failed outside a handler
     * @throws DatabaseException When the connection's state refuses the memory-only commit, or a test scope is closing
     */
    public function open(
        string $sessionTokenHash,
        int $userId,
        string $requestId,
        string $originAcceptKey,
        string $name,
        int $startedAt,
        ?int $deadlineAt,
    ): void {
        $this->ensureCanWrite();
        $attempt = StateHilosProfileNameChange::create(
            $sessionTokenHash,
            $userId,
            $requestId,
            $originAcceptKey,
            $name,
            $startedAt,
            $deadlineAt,
        );
        $level = Database::runtimeTransactionLevel();
        if ($level !== null && $level->testScope === null) {
            $this->replaceAttempt($attempt);

            return;
        }
        Database::transactionStart();
        try {
            $this->replaceAttempt($attempt);
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
    }

    /**
     * The compound of {@see self::open()}: the prior attempt goes, the new one is born checking.
     *
     * @param StateHilosProfileNameChange $attempt New attempt row
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws HilosException Whatever preparing the RT write's immutable operation raises
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written by that door
     * @throws RtActionsStateCollectionNullException When the state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the collection
     * @throws SourceChangeSubscriberException When a membership subscriber fails
     */
    private function replaceAttempt(StateHilosProfileNameChange $attempt): void
    {
        if ($this->stateCollection->get($attempt->getId()) !== null) {
            $this->removeStateFromCollection($attempt->getId());
        }
        $this->addStateToCollection($attempt);
    }
}
