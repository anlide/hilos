<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosStatusComponents as StateHilosStatusComponents;
use Hilos\Runtime\State\Item\HilosStatusComponent as StateHilosStatusComponent;
use Hilos\Runtime\View\Collection\HilosStatusComponents;
use Hilos\Runtime\View\Item\HilosStatusComponent as ViewHilosStatusComponent;

/**
 * @extends RtActions<ViewHilosStatusComponent, HilosStatusComponents, StateHilosStatusComponents>
 * @property-read StateHilosStatusComponents $stateCollection Backing component rows
 */
final class HilosStatusComponentsActions extends RtActions
{
    /**
     * Leaves an existing row intact; refreshing its observation is an item operation.
     *
     * @param string $componentKey Declared component key
     * @param int $expectedInstances Initial expected slots
     * @throws InvalidFormatException When the new row's key or expected count is invalid
     * @throws RtActionsCollectionNameNullException When the collection is unmounted
     * @throws RtActionsStateCollectionNullException When backing state is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller cannot add this row
     * @throws SourceChangeSubscriberException When a source-change subscriber fails
     */
    public function ensure(string $componentKey, int $expectedInstances): void
    {
        if (isset($this->collection[$componentKey])) {
            return;
        }
        $this->ensureCanWriteState($componentKey, [], TruthSourceOperation::Add);
        $this->addStateToCollection(StateHilosStatusComponent::create($componentKey, $expectedInstances));
    }
}
