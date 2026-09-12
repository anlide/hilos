<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Local port the deferred-queue holder uses to hand a batch to the owner of what is in it (HIL-846).
 *
 * The sending half of {@see DeferredQueueHandover}: when a pass finds a batch to offer, the holder
 * names the hand-over signal and its payload and leaves the delivery to whoever it runs inside, so
 * the batch reaches its owner exactly as any other agent-to-agent frame would - routed by name, and
 * carried to another node when the owner is placed there. {@see BackupAgent} implements it with its
 * own send-to-agent path. A test supplies a fake so the holder runs without an agent or a router.
 */
interface DeferredQueueHandoverSink
{
    /**
     * Sends one hand-over frame to the agent that declares its name.
     *
     * @param string $signalName Hand-over signal name
     * @param SignalDataInterface $data The batch, as the owner reads it
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function handOverDeferredQueue(string $signalName, SignalDataInterface $data): void;
}
