<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

/**
 * Row-scoped DB sync payload: a create, an update or a delete of one row.
 *
 * DbSyncClearedSignalData is deliberately outside this interface — a truncate is
 * collection-scoped and carries neither a row id nor row data.
 */
interface DbSyncSignalDataInterface extends EmitterStampedSyncSignalDataInterface
{
    /** @var string Row ID from Object::getIdString() */
    public string $idString { get; }

    /** @var array<string, mixed> Row data carried by this fact */
    public array $row { get; }

    /** @var ?int Existing receipt primary key for the write, or null outside a receipt */
    public ?int $receiptId { get; }

    /** @var ?string Writing agent under the receipt, or null without an active agent */
    public ?string $writerAgentId { get; }
}
