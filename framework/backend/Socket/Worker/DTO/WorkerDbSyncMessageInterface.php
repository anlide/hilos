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

    /**
     * @var ?string Node the write happened on, or null when it was this one.
     *
     * On the interface rather than on each frame because the one handler that reads all three
     * has to pass it on, and a frame that carried it privately would leave that handler
     * applying a row from another node as if this node had written it (HIL-670).
     */
    public ?string $originNodeId { get; }
}
