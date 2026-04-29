<?php

declare(strict_types=1);

namespace Hilos\Core\Frontend;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;

/**
 * Worker-local frontend projection accumulator.
 *
 * The projection is intentionally not stored in the daemon. DB_SYNC/RT_SYNC
 * facts invalidate the projection of every worker that receives them, including
 * facts that originated in another worker. During flush, each worker targets
 * only accept keys present in its own local subscription mirror. The daemon
 * remains a transport/router for already-addressed WebSocket frames, so two
 * workers do not broadcast duplicate frontend updates to the same connection.
 */
abstract class FrontendProjectionContext
{
    private SourceChangeSet $changes;

    public function __construct()
    {
        $this->changes = new SourceChangeSet();
    }

    public function record(SourceChange $change): void
    {
        $this->changes->add($change);
    }

    public function hasChanges(): bool
    {
        return !$this->changes->isEmpty();
    }

    public function flushToSignalRouter(): void
    {
        if ($this->changes->isEmpty() || Hilos::$sr === null) {
            return;
        }

        $changes = $this->changes;
        $this->changes = new SourceChangeSet();

        foreach ($this->buildDeliveries($changes) as $delivery) {
            if (!$delivery instanceof FrontendDelivery || $delivery->targetAcceptKey === '') {
                continue;
            }

            Hilos::$sr->queueSignal(
                signalSource: new SignalSource(SignalSource::WORKER),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName($delivery->wireSignalName),
                signalData: new WebSocketSignalData(
                    data: $delivery->payload,
                    targetAcceptKey: $delivery->targetAcceptKey,
                ),
            );
        }
    }

    /**
     * @return iterable<FrontendDelivery>
     */
    abstract protected function buildDeliveries(SourceChangeSet $changes): iterable;
}
