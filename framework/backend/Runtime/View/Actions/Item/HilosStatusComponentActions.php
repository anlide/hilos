<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosStatusComponent as StateHilosStatusComponent;
use Hilos\Runtime\View\Item\HilosStatusComponent as ViewHilosStatusComponent;

/**
 * @extends RtActions<ViewHilosStatusComponent, StateHilosStatusComponent>
 * @property-read StateHilosStatusComponent $state Current component counts
 */
final class HilosStatusComponentActions extends RtActions
{
    /**
     * @param int $expectedInstances Expected slots, at least one
     * @param ?int $liveInstances Live slots, or null without a complete observation
     * @param ?int $observedAt Observation epoch milliseconds UTC, or null
     * @throws InvalidFormatException When either count is outside its domain
     * @throws RtActionsCollectionNameNullException When the collection is unmounted
     * @throws RtTruthSourceWriteNotAllowedException When the caller cannot update this row
     */
    public function update(int $expectedInstances, ?int $liveInstances, ?int $observedAt): void
    {
        $this->ensureCanWrite();
        if ($expectedInstances < 1 || ($liveInstances !== null && $liveInstances < 0)) {
            throw new InvalidFormatException('Status counts require positive expected slots and nonnegative live slots.');
        }
        $this->state->expectedInstances = $expectedInstances;
        $this->state->liveInstances = $liveInstances;
        $this->state->observedAt = $observedAt;
        $this->sync();
    }
}
