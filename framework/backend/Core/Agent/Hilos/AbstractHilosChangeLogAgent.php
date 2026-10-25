<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Hilos;
use Hilos\HilosException;

/** Cluster reader for the Change Log section, hosted in a dedicated worker. */
abstract class AbstractHilosChangeLogAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_CHANGE_LOG;

    public const array OWNS_DB = [HilosDbContext::changeLogReceipts => [TruthSourceOperation::Update]];

    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::changeLogTables,
        HilosDbContext::changeLogFields,
        HilosDbContext::changeLogEntries,
        HilosDbContext::changeLogChanges,
        HilosDbContext::changeLogValues,
    ];

    /**
     * Adds every mounted journaled collection using Entity metadata alone.
     *
     * @return list<string> Declared reads and journaled framework/project keys, each named once
     * @throws InvalidMountedCollectionException When a mounted collection has no valid Entity
     */
    public static function readDbCollections(): array
    {
        return array_values(array_unique([...parent::readDbCollections(), ...array_keys(JournaledTables::mounted())]));
    }

    /**
     * Attributes a journaled create; absent metadata and nonjournaled facts are ignored.
     *
     * @param DbSyncCreatedSignalData $data Business row fact
     * @param string $source Transport source, independent of the writer
     * @param string $name Existing DB-sync signal name
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        $this->recordWriterFromDbSync($data);
    }

    /**
     * Attributes a journaled update; receipt updates cannot feed back into attribution.
     *
     * @param DbSyncUpdatedSignalData $data Business row fact
     * @param string $source Transport source, independent of the writer
     * @param string $name Existing DB-sync signal name
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        $this->recordWriterFromDbSync($data);
    }

    /**
     * Attributes a journaled delete using the writer captured before the row disappeared.
     *
     * @param DbSyncDeletedSignalData $data Business row fact
     * @param string $source Transport source, independent of the writer
     * @param string $name Existing DB-sync signal name
     */
    public function onSignalDbSyncDeleted(DbSyncDeletedSignalData $data, string $source, string $name): void
    {
        $this->recordWriterFromDbSync($data);
    }

    /**
     * Best-effort attribution cannot change the outcome of the completed business write.
     *
     * @param DbSyncSignalDataInterface $data Committed row fact carrying its original writer
     */
    private function recordWriterFromDbSync(DbSyncSignalDataInterface $data): void
    {
        if ($data->receiptId === null || $data->receiptId <= 0 || $data->writerAgentId === null || $data->writerAgentId === '') {
            return;
        }

        try {
            if (!isset(JournaledTables::mounted()[$data->collectionKey])) {
                return;
            }

            Hilos::$db->changeLogReceipts->actions->recordWriter($data->receiptId, $data->writerAgentId);
        // read-refusal-swallowed: attribution is best-effort after the business write; every failure is logged
        } catch (HilosException $failure) {
            $this->logAgentError("Cannot attribute journal receipt {$data->receiptId}: {$failure->getMessage()}");
        }
    }
}
