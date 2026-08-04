<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

/**
 * Row-scoped DB sync payload: a create, an update or a delete of one row.
 *
 * DbSyncClearedSignalData is deliberately outside this interface — a truncate is
 * collection-scoped and carries neither a row id nor row data.
 */
interface DbSyncSignalDataInterface extends SyncSignalDataInterface
{
    /** @var string Row ID from Object::getIdString() */
    public string $idString { get; }

    /** @var array<string, mixed> Row data carried by this fact */
    public array $row { get; }
}
