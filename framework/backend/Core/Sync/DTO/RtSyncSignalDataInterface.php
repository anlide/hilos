<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

/**
 * State-scoped RT sync payload: a create, an update or a delete of one state item.
 */
interface RtSyncSignalDataInterface extends SyncSignalDataInterface
{
    /** @var string State ID */
    public string $stateId { get; }

    /** @var array<string, mixed> State data carried by this fact */
    public array $row { get; }
}
