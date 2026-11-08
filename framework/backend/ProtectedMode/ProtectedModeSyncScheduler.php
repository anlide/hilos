<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;

/** The clustered slave-sync owner's process-local scheduling port; standalone has none. */
interface ProtectedModeSyncScheduler
{
    /** @param EventLoop $loop Existing master loop, shared with peer transport */
    public function attachSlaveSyncEventLoop(EventLoop $loop): void;

    /** Releases native and queued handles before the peer base closes. */
    public function detachSlaveSyncEventLoop(): void;

    /** Records an actual source, role or installed-link change without applying effects. */
    public function noteSlaveSyncChanged(): void;

    /** @return bool Whether a finite portion or native deadline owes guarded work */
    public function hasSlaveSyncReadyWork(): bool;

    /**
     * @param ?float $wallNow Paired epoch observation, current clock by default
     * @param ?float $monotonicNow Paired process observation, current clock by default
     * @throws EventLoopException When the original request deadline cannot be armed
     * @throws EnvException When leadership or timeout configuration is invalid
     * @throws PeerTransportException When a snapshot or acknowledgement is invalid
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this master cannot write the freeze row
     */
    public function dispatchSlaveSyncReady(?float $wallNow = null, ?float $monotonicNow = null): void;
}
