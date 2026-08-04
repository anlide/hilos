<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;

/**
 * Daemon-to-worker message carrying a row-scoped DB sync payload.
 *
 * Marks the three transport messages the worker handles the same way, so the
 * create/update/delete handlers collapse into one that picks its applicator from
 * the payload class.
 */
interface WorkerDbSyncMessageInterface
{
    /** @var DbSyncSignalDataInterface Row-scoped DB sync payload of this message */
    public DbSyncSignalDataInterface $signalData { get; }
}
