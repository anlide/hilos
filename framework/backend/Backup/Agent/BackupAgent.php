<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent;

use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupScanResult;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;

/**
 * BackupAgent - the monopoly backup subsystem agent (framework-owned, concrete).
 *
 * Projects activate it by registering it in Hilos::AGENTS under
 * {@see HilosAgentType::HILOS_BACKUP}; it needs no subclass because all behavior
 * is driven by env and the backup catalog. In this foundation it owns the read
 * path only: on start it scans the backup storage tree and rebuilds the runtime
 * backup index (files=truth, RT=index). The create/rotation write paths land in
 * HIL-270/HIL-272.
 */
final class BackupAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_BACKUP;

    /**
     * Rebuilds the runtime backup index from the storage tree, or no-ops when disabled.
     */
    public function onStart(): void
    {
        if (!Hilos::$env->bool(EnvConstants::BACKUP_ENABLED)) {
            $this->logAgentInfo('Backup disabled; skipping history scan');

            return;
        }

        $this->registerRtTruthSource(BackupHistory::RT_COLLECTION);

        $result = (new BackupHistoryScanner())->scan(Hilos::$env->string(EnvConstants::BACKUP_DIR));
        $this->rebuildHistory($result);
        $this->reportAnomalies($result);

        $this->logAgentInfo(sprintf(
            'Backup history rebuilt: %d entries, %d anomalies',
            count($result->metadatas),
            count($result->anomalies),
        ));
    }

    /**
     * No incremental work in the foundation; create/rotation ticks land in HIL-270/HIL-272.
     */
    public function onTick(): void
    {
        // Default: do nothing
    }

    /**
     * No teardown needed; the runtime index is transient and rebuilt on next start.
     */
    public function onStop(): void
    {
        // Default: do nothing
    }

    /**
     * Replaces the runtime backup index with the scanned metadata.
     *
     * The typed browser view/representation lands in HIL-278; until then the
     * monopoly agent owns the index directly on its registered state collection.
     *
     * @param BackupScanResult $result Scan result to project into runtime state
     */
    private function rebuildHistory(BackupScanResult $result): void
    {
        $histories = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);
        if (!$histories instanceof BackupHistories) {
            return;
        }

        $histories->clear();
        foreach ($result->metadatas as $metadata) {
            $histories->add(BackupHistory::fromMetadata($metadata));
        }
    }

    /**
     * Logs each scan anomaly at its severity; anomalies never fail the scan.
     *
     * @param BackupScanResult $result Scan result whose anomalies are logged
     */
    private function reportAnomalies(BackupScanResult $result): void
    {
        foreach ($result->anomalies as $anomaly) {
            $message = "Backup scan anomaly [{$anomaly->type->value}] at {$anomaly->path}";
            if ($anomaly->type->isError()) {
                $this->logAgentError($message);
            } else {
                $this->logAgentWarning($message);
            }
        }
    }
}
