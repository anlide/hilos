<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\BackupMetadataIncompleteException;

/**
 * BackupHistoryScanner - the sidecar-driven read path for the backup index.
 *
 * Walks `<root>/<scope>/` for each {@see BackupScope} and pairs each `*.json`
 * sidecar with its `*.tar.gz` archive. The sidecar is the source of truth: the
 * index is built from sidecars, archives are only checked for existence.
 *
 * Pairing rules:
 * - unreadable/unparseable sidecar, or one that names no backup → {@see BackupScanAnomalyType::BROKEN_SIDECAR}, skipped;
 * - sidecar with status=error → included as a failure row regardless of archive;
 * - success sidecar without archive → {@see BackupScanAnomalyType::SIDECAR_WITHOUT_TAR}, skipped;
 * - success sidecar with archive → included;
 * - archive without sidecar → {@see BackupScanAnomalyType::TAR_WITHOUT_SIDECAR}, skipped.
 */
final class BackupHistoryScanner
{
    public const string SIDECAR_EXTENSION = '.json';
    public const string ARCHIVE_EXTENSION = '.tar.gz';

    /**
     * Scans the backup storage tree and returns the index and anomalies.
     *
     * A missing or empty root yields an empty result; the scan never throws.
     *
     * @param string $root Backup storage root directory
     * @return BackupScanResult Indexable metadata and anomalies
     */
    public function scan(string $root): BackupScanResult
    {
        $metadatas = [];
        $anomalies = [];

        if ($root === '' || !is_dir($root)) {
            return new BackupScanResult([], []);
        }

        foreach (BackupScope::cases() as $scope) {
            $scopeDir = $root . '/' . $scope->value;
            if (is_dir($scopeDir)) {
                $this->scanScope($scopeDir, $metadatas, $anomalies);
            }
        }

        return new BackupScanResult($metadatas, $anomalies);
    }

    /**
     * Scans one scope directory, appending to the shared metadata and anomaly lists.
     *
     * @param string $scopeDir Scope directory to scan
     * @param list<BackupMetadata> $metadatas Accumulated indexable metadata
     * @param list<BackupScanAnomaly> $anomalies Accumulated anomalies
     */
    private function scanScope(string $scopeDir, array &$metadatas, array &$anomalies): void
    {
        $seenIds = [];

        foreach (glob($scopeDir . '/*' . self::SIDECAR_EXTENSION) ?: [] as $sidecarPath) {
            $id = basename($sidecarPath, self::SIDECAR_EXTENSION);
            $seenIds[$id] = true;

            // warning-suppressed: an unreadable sidecar becomes a BROKEN_SIDECAR anomaly below, the scan never throws
            $raw = @file_get_contents($sidecarPath);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (!is_array($decoded)) {
                $anomalies[] = new BackupScanAnomaly(BackupScanAnomalyType::BROKEN_SIDECAR, $sidecarPath);
                continue;
            }

            try {
                $metadata = BackupMetadata::fromArray($decoded);
            } catch (BackupMetadataIncompleteException) {
                $anomalies[] = new BackupScanAnomaly(BackupScanAnomalyType::BROKEN_SIDECAR, $sidecarPath);
                continue;
            }

            if ($metadata->status === BackupStatus::ERROR) {
                $metadatas[] = $metadata;
                continue;
            }

            if (!is_file($scopeDir . '/' . $id . self::ARCHIVE_EXTENSION)) {
                $anomalies[] = new BackupScanAnomaly(BackupScanAnomalyType::SIDECAR_WITHOUT_TAR, $sidecarPath);
                continue;
            }

            $metadatas[] = $metadata;
        }

        foreach (glob($scopeDir . '/*' . self::ARCHIVE_EXTENSION) ?: [] as $archivePath) {
            $id = basename($archivePath, self::ARCHIVE_EXTENSION);
            if (!isset($seenIds[$id])) {
                $anomalies[] = new BackupScanAnomaly(BackupScanAnomalyType::TAR_WITHOUT_SIDECAR, $archivePath);
            }
        }
    }
}
