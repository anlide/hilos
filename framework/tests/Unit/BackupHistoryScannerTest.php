<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScanAnomalyType;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sidecar-driven backup storage scan (read path).
 */
final class BackupHistoryScannerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-backup-scan-' . uniqid('', true);
        mkdir($this->root . '/' . BackupScope::FULL->value, 0777, true);
        mkdir($this->root . '/' . BackupScope::SCHEMA_ONLY->value, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testMissingRootYieldsEmptyResult(): void
    {
        $result = (new BackupHistoryScanner())->scan($this->root . '/does-not-exist');

        $this->assertSame([], $result->metadatas);
        $this->assertSame([], $result->anomalies);
    }

    public function testEmptyRootStringYieldsEmptyResult(): void
    {
        $result = (new BackupHistoryScanner())->scan('');

        $this->assertSame([], $result->metadatas);
        $this->assertSame([], $result->anomalies);
    }

    public function testScanIndexesValidAndErrorRecordsAndClassifiesAnomalies(): void
    {
        // Valid success backup: sidecar + archive.
        $this->writeSidecar(BackupScope::FULL, 'ok1', $this->metadataArray('ok1', BackupScope::FULL, BackupStatus::SUCCESS));
        $this->writeArchive(BackupScope::FULL, 'ok1');

        // Recorded failure: sidecar with status=error, no archive → indexed anyway.
        $this->writeSidecar(BackupScope::FULL, 'fail1', $this->metadataArray('fail1', BackupScope::FULL, BackupStatus::ERROR));

        // Success sidecar without archive → phantom, skipped + warn.
        $this->writeSidecar(BackupScope::FULL, 'phantom1', $this->metadataArray('phantom1', BackupScope::FULL, BackupStatus::SUCCESS));

        // Archive without sidecar → orphan, skipped + warn.
        $this->writeArchive(BackupScope::FULL, 'orphan1');

        // Broken JSON sidecar → skipped + error.
        file_put_contents($this->scopePath(BackupScope::SCHEMA_ONLY, 'broken1' . BackupHistoryScanner::SIDECAR_EXTENSION), '{ not json');

        $result = (new BackupHistoryScanner())->scan($this->root);

        $indexedIds = array_map(
            static fn(BackupMetadata $metadata): string => $metadata->id,
            $result->metadatas,
        );
        sort($indexedIds);
        $this->assertSame(['fail1', 'ok1'], $indexedIds);

        $anomalies = [];
        foreach ($result->anomalies as $anomaly) {
            $anomalies[$anomaly->type->value] = ($anomalies[$anomaly->type->value] ?? 0) + 1;
        }
        $this->assertSame(1, $anomalies[BackupScanAnomalyType::SIDECAR_WITHOUT_TAR->value] ?? 0);
        $this->assertSame(1, $anomalies[BackupScanAnomalyType::TAR_WITHOUT_SIDECAR->value] ?? 0);
        $this->assertSame(1, $anomalies[BackupScanAnomalyType::BROKEN_SIDECAR->value] ?? 0);
    }

    /**
     * @return array<string, mixed> Minimal sidecar payload
     */
    private function metadataArray(string $id, BackupScope $scope, BackupStatus $status): array
    {
        return [
            BackupMetadata::id => $id,
            BackupMetadata::createdAt => '2026-07-18T00:00:00+00:00',
            BackupMetadata::env => 'test',
            BackupMetadata::scope => $scope->value,
            BackupMetadata::connections => [],
            BackupMetadata::sizeBytes => 1,
            BackupMetadata::durationSeconds => 1,
            BackupMetadata::keep => false,
            BackupMetadata::status => $status->value,
        ];
    }

    /**
     * @param array<string, mixed> $data Sidecar payload
     */
    private function writeSidecar(BackupScope $scope, string $id, array $data): void
    {
        file_put_contents(
            $this->scopePath($scope, $id . BackupHistoryScanner::SIDECAR_EXTENSION),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function writeArchive(BackupScope $scope, string $id): void
    {
        file_put_contents($this->scopePath($scope, $id . BackupHistoryScanner::ARCHIVE_EXTENSION), 'archive');
    }

    private function scopePath(BackupScope $scope, string $file): string
    {
        return $this->root . '/' . $scope->value . '/' . $file;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
