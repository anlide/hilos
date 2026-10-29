<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosStatusMaintenance as StateHilosStatusMaintenanceCollection;
use Hilos\Runtime\State\Item\HilosStatusMaintenance as StateHilosStatusMaintenance;
use Hilos\Runtime\View\Collection\HilosStatusMaintenance as ViewHilosStatusMaintenanceCollection;
use Hilos\Runtime\View\Item\HilosStatusMaintenance as ViewHilosStatusMaintenance;

/**
 * @extends RtActions<ViewHilosStatusMaintenance, ViewHilosStatusMaintenanceCollection, StateHilosStatusMaintenanceCollection>
 * @property-read StateHilosStatusMaintenanceCollection $stateCollection Backing maintenance rows
 */
final class HilosStatusMaintenanceActions extends RtActions
{
    /**
     * Leaves existing facts intact, including an active fact whose source is unavailable.
     *
     * @throws InvalidFormatException When initial state is invalid
     * @throws RtActionsCollectionNameNullException When the collection is unmounted
     * @throws RtActionsStateCollectionNullException When backing state is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller cannot add the singleton
     * @throws SourceChangeSubscriberException When a source-change subscriber fails
     */
    public function ensure(): void
    {
        if (isset($this->collection[StateHilosStatusMaintenance::CURRENT])) {
            return;
        }
        $this->ensureCanWriteState(StateHilosStatusMaintenance::CURRENT, [], TruthSourceOperation::Add);
        $this->addStateToCollection(StateHilosStatusMaintenance::create());
    }
}
