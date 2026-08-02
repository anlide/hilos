<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\Exception\BackupException;
use Hilos\Core\CLI\Commands\BackupTestAgeCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the age fixture primitive - the sidecar createdAt rewrite that lets a test
 * make a backup look old enough to rotate without waiting out real time. Exercises the pure
 * file logic ({@see BackupTestAgeCommand::retimeSidecar()}) against a temp backup tree.
 */
final class BackupTestAgeCommandTest extends TestCase
{
    private const string ENV = 'test';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-age-test-' . getmypid() . '-' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testRetimeRewritesOnlyCreatedAt(): void
    {
        $id = '2026-07-19_10-30-00';
        $this->writeSidecar($id, BackupScope::FULL, '2026-07-19T10:30:00+00:00', keep: true);

        $newInstant = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $path = new BackupTestAgeCommand()->retimeSidecar($this->root, $id, null, $newInstant);

        $rewritten = $this->readSidecar($path);
        $this->assertSame($newInstant->format(DateTimeInterface::ATOM), $rewritten->createdAt);
        $this->assertSame($id, $rewritten->id);
        $this->assertSame(self::ENV, $rewritten->env);
        $this->assertSame(BackupScope::FULL, $rewritten->scope);
        $this->assertTrue($rewritten->keep);
        $this->assertSame(BackupStatus::SUCCESS, $rewritten->status);
    }

    public function testDaysOptionResolvesToPastCreatedAtViaTheCommand(): void
    {
        $id = '2026-07-19_10-30-00';
        $this->writeSidecar($id, BackupScope::FULL, '2026-07-19T10:30:00+00:00');

        // The command computes createdAt from now minus N days; assert the sidecar moved back.
        $before = new DateTimeImmutable('-40 days');
        new BackupTestAgeCommand()->retimeSidecar($this->root, $id, BackupScope::FULL, $before);

        $rewritten = $this->readSidecar($this->sidecarPath($id, BackupScope::FULL));
        $this->assertLessThan(
            new DateTimeImmutable('-30 days')->getTimestamp(),
            new DateTimeImmutable($rewritten->createdAt)->getTimestamp(),
        );
    }

    public function testRetimeThrowsWhenNoSidecarMatches(): void
    {
        $this->expectException(BackupException::class);
        new BackupTestAgeCommand()->retimeSidecar($this->root, 'missing-id', null, new DateTimeImmutable());
    }

    public function testRetimeThrowsWhenIdIsAmbiguousAcrossScopes(): void
    {
        $id = '2026-07-19_10-30-00';
        $this->writeSidecar($id, BackupScope::FULL, '2026-07-19T10:30:00+00:00');
        $this->writeSidecar($id, BackupScope::SCHEMA_ONLY, '2026-07-19T10:30:00+00:00');

        $this->expectException(BackupException::class);
        new BackupTestAgeCommand()->retimeSidecar($this->root, $id, null, new DateTimeImmutable());
    }

    public function testScopeNarrowsAnAmbiguousIdToOneMatch(): void
    {
        $id = '2026-07-19_10-30-00';
        $this->writeSidecar($id, BackupScope::FULL, '2026-07-19T10:30:00+00:00');
        $this->writeSidecar($id, BackupScope::SCHEMA_ONLY, '2026-07-19T10:30:00+00:00');

        $newInstant = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $path = new BackupTestAgeCommand()->retimeSidecar($this->root, $id, BackupScope::SCHEMA_ONLY, $newInstant);

        // Only the schema-only sidecar moved; the full sidecar is untouched.
        $this->assertSame($this->sidecarPath($id, BackupScope::SCHEMA_ONLY), $path);
        $this->assertSame($newInstant->format(DateTimeInterface::ATOM), $this->readSidecar($path)->createdAt);
        $this->assertSame(
            '2026-07-19T10:30:00+00:00',
            $this->readSidecar($this->sidecarPath($id, BackupScope::FULL))->createdAt,
        );
    }

    /**
     * Writes a minimal success sidecar for the given id/scope with the given createdAt.
     */
    private function writeSidecar(string $id, BackupScope $scope, string $createdAt, bool $keep = false): void
    {
        $scopeDir = $this->root . '/' . $scope->value;
        if (!is_dir($scopeDir)) {
            mkdir($scopeDir, 0755, true);
        }

        $metadata = new BackupMetadata(
            $id,
            $createdAt,
            self::ENV,
            $scope,
            [],
            1024,
            3,
            $keep,
            BackupStatus::SUCCESS,
        );

        file_put_contents(
            $this->sidecarPath($id, $scope),
            json_encode($metadata->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function sidecarPath(string $id, BackupScope $scope): string
    {
        $base = BackupCreator::archiveBaseName($id, self::ENV, $scope);

        return $this->root . '/' . $scope->value . '/' . $base . '.json';
    }

    private function readSidecar(string $path): BackupMetadata
    {
        $raw = (string)file_get_contents($path);

        return BackupMetadata::fromArray((array)json_decode($raw, true));
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
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
