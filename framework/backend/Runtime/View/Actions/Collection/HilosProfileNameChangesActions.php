<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosProfileNameChanges as StateHilosProfileNameChanges;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\View\Collection\HilosProfileNameChanges;
use Hilos\Runtime\View\Item\HilosProfileNameChange;

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
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param int $userId Person the attempt belongs to
     * @param string $requestId Fresh secure 32-hex attempt identifier
     * @param string $originAcceptKey Connection that submitted the attempt
     * @param string $name Trimmed requested name
     * @param int $startedAt Attempt start in epoch milliseconds
     * @param ?int $deadlineAt Fixed checking deadline in epoch milliseconds, or null
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not own the collection
     * @throws SourceChangeSubscriberException When a membership subscriber fails
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

        if ($this->stateCollection->get($sessionTokenHash) !== null) {
            $this->removeStateFromCollection($sessionTokenHash);
        }
        $this->addStateToCollection(StateHilosProfileNameChange::create(
            $sessionTokenHash,
            $userId,
            $requestId,
            $originAcceptKey,
            $name,
            $startedAt,
            $deadlineAt,
        ));
    }
}
