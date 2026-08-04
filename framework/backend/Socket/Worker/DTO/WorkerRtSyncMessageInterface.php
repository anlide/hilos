<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;

/**
 * Daemon-to-worker message carrying a state-scoped RT sync payload.
 *
 * Marks the three transport messages the worker handles the same way, so the
 * create/update/delete handlers collapse into one that picks its applicator from
 * the payload class.
 */
interface WorkerRtSyncMessageInterface
{
    /** @var RtSyncSignalDataInterface State-scoped RT sync payload of this message */
    public RtSyncSignalDataInterface $signalData { get; }
}
