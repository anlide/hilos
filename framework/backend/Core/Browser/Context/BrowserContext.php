<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\Context;

use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SourceChangeSet;

/**
 * Base browser-facing context.
 *
 * Project subclasses register browser-facing state helpers during configuration.
 */
abstract class BrowserContext
{
    /** Browser-only table configs keyed by browser table name. */
    public const array TABLES = [];

    protected SourceChangeSet $changes;

    /**
     * Starts with an empty worker-local browser source-change buffer.
     */
    public function __construct()
    {
        $this->changes = new SourceChangeSet();
    }

    /**
     * Registers browser-facing state helpers.
     *
     * Called during Hilos::init().
     */
    abstract public function configure(): void;

    /**
     * Records a DB/RT sync fact in the worker-local browser buffer.
     *
     * @param SourceChange $change Source change to dispatch on the next browser flush
     */
    public function record(SourceChange $change): void
    {
        $this->changes->add($change);
    }

    /**
     * Reports whether any browser source changes are buffered.
     *
     * @return bool Whether the browser context has source changes waiting for flush
     */
    public function hasChanges(): bool
    {
        return !$this->changes->isEmpty();
    }

    /**
     * Drains browser source changes at the end of the worker tick.
     */
    public function flushToSignalRouter(): void
    {
        if ($this->changes->isEmpty()) {
            return;
        }

        $this->groupSourceChanges();
        $this->emitBrowserSignals();

        $this->changes = new SourceChangeSet();
    }

    /**
     * Groups source changes before browser signal work by modifying $this->changes.
     *
     * The default implementation keeps the source-change buffer as-is.
     */
    protected function groupSourceChanges(): void
    {
        // TODO: Collapse repeated DB/RT source changes within $this->changes before browser emit.
    }

    /**
     * Emits browser signals produced from grouped DB/RT source changes in $this->changes.
     */
    protected function emitBrowserSignals(): void
    {
        // TODO: Build and queue browser-facing signals here.
    }
}
