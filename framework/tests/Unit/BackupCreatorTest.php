<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\Exception\BackupException;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Runtime\State\Item\BackupHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, DB-free logic of the backup create engine.
 *
 * The mysqldump/archive path needs a live database and is exercised at e2e; here we
 * pin the scope-to-passes mapping, the archive naming, the defaults-file rendering,
 * and the up-front id validation.
 */
final class BackupCreatorTest extends TestCase
{
    public function testFullScopeIsOneUnrestrictedPass(): void
    {
        $passes = BackupCreator::scopeDumpPasses(BackupScope::FULL, []);

        $this->assertCount(1, $passes);
        $this->assertSame([], $passes[0]['flags']);
        $this->assertSame([], $passes[0]['tables']);
        $this->assertFalse($passes[0]['append']);
    }

    public function testSchemaOnlyScopeIsOneNoDataPass(): void
    {
        $passes = BackupCreator::scopeDumpPasses(BackupScope::SCHEMA_ONLY, ['users']);

        $this->assertCount(1, $passes);
        $this->assertSame(['--no-data'], $passes[0]['flags']);
        $this->assertSame([], $passes[0]['tables']);
    }

    public function testSchemaSeedWithoutReferenceTablesCollapsesToSchemaOnly(): void
    {
        $passes = BackupCreator::scopeDumpPasses(BackupScope::SCHEMA_SEED, []);

        $this->assertCount(1, $passes);
        $this->assertSame(['--no-data'], $passes[0]['flags']);
        $this->assertFalse($passes[0]['append']);
    }

    public function testSchemaSeedWithReferenceTablesAppendsDataPass(): void
    {
        $passes = BackupCreator::scopeDumpPasses(BackupScope::SCHEMA_SEED, ['roles', 'settings']);

        $this->assertCount(2, $passes);
        $this->assertSame(['--no-data'], $passes[0]['flags']);
        $this->assertFalse($passes[0]['append']);
        $this->assertSame(['--no-create-info'], $passes[1]['flags']);
        $this->assertSame(['roles', 'settings'], $passes[1]['tables']);
        $this->assertTrue($passes[1]['append']);
    }

    public function testArchiveBaseNameJoinsIdEnvScope(): void
    {
        $this->assertSame(
            '2026-07-19_10-30-00-prod-full',
            BackupCreator::archiveBaseName('2026-07-19_10-30-00', 'prod', BackupScope::FULL),
        );
    }

    public function testDefaultsIniCarriesCredentialsAndOmitsAbsentSocket(): void
    {
        $ini = BackupCreator::renderDefaultsIni($this->config(socket: null));

        $this->assertStringContainsString('[mysqldump]', $ini);
        $this->assertStringContainsString('host = "db-host"', $ini);
        $this->assertStringContainsString('port = 3307', $ini);
        $this->assertStringContainsString('user = "dumper"', $ini);
        $this->assertStringContainsString('password = "secret"', $ini);
        $this->assertStringNotContainsString('socket', $ini);
    }

    public function testDefaultsIniIncludesSocketWhenSet(): void
    {
        $ini = BackupCreator::renderDefaultsIni($this->config(socket: '/tmp/mysql.sock'));

        $this->assertStringContainsString('socket = "/tmp/mysql.sock"', $ini);
    }

    public function testDefaultsIniEscapesQuotesAndBackslashes(): void
    {
        $ini = BackupCreator::renderDefaultsIni($this->config(password: 'a"b\\c'));

        $this->assertStringContainsString('password = "a\\"b\\\\c"', $ini);
    }

    public function testCreateRejectsAnIdWithPathSeparators(): void
    {
        $this->expectException(BackupException::class);

        (new BackupCreator())->create('../escape', BackupScope::FULL);
    }

    public function testSetStoredKeepPinsTheSidecarAndPreservesEveryOtherField(): void
    {
        $root = $this->makeRoot();
        $original = new BackupMetadata(
            id: 'bk1',
            createdAt: '2026-07-20T03:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 12,
            keep: false,
            status: BackupStatus::SUCCESS,
            warnings: ['note'],
        );
        $this->writeSidecar($root, $original);

        (new BackupCreator())->setStoredKeep($this->rowFor($original), $root, true);

        $reloaded = $this->readSidecar($root, $original);
        $this->assertTrue($reloaded->keep);
        $this->assertSame($original->id, $reloaded->id);
        $this->assertSame($original->createdAt, $reloaded->createdAt);
        $this->assertSame($original->sizeBytes, $reloaded->sizeBytes);
        $this->assertSame($original->durationSeconds, $reloaded->durationSeconds);
        $this->assertSame(BackupStatus::SUCCESS, $reloaded->status);
        $this->assertSame(['note'], $reloaded->warnings);
    }

    public function testSetStoredKeepClearsThePin(): void
    {
        $root = $this->makeRoot();
        $original = $this->metadata(keep: true);
        $this->writeSidecar($root, $original);

        (new BackupCreator())->setStoredKeep($this->rowFor($original), $root, false);

        $this->assertFalse($this->readSidecar($root, $original)->keep);
    }

    public function testSetStoredKeepIsANoOpWhenAlreadyAtTarget(): void
    {
        $root = $this->makeRoot();
        $original = $this->metadata(keep: true);
        $path = $this->writeSidecar($root, $original);
        $mtimeBefore = filemtime($path);

        // A no-change target must not rewrite the sidecar at all.
        clearstatcache();
        (new BackupCreator())->setStoredKeep($this->rowFor($original), $root, true);

        clearstatcache();
        $this->assertSame($mtimeBefore, filemtime($path));
        $this->assertTrue($this->readSidecar($root, $original)->keep);
    }

    public function testSetStoredKeepThrowsWhenTheSidecarIsMissing(): void
    {
        $this->expectException(BackupException::class);

        (new BackupCreator())->setStoredKeep($this->rowFor($this->metadata()), $this->makeRoot(), true);
    }

    /**
     * @param bool $keep Initial keep pin
     * @return BackupMetadata A minimal success metadata for keep tests
     */
    private function metadata(bool $keep = false): BackupMetadata
    {
        return new BackupMetadata(
            id: 'bk1',
            createdAt: '2026-07-20T03:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 1024,
            durationSeconds: 5,
            keep: $keep,
            status: BackupStatus::SUCCESS,
        );
    }

    /**
     * @param BackupMetadata $metadata Sidecar to mirror as an index row
     * @return BackupHistory Index row identifying the backup
     */
    private function rowFor(BackupMetadata $metadata): BackupHistory
    {
        return BackupHistory::fromMetadata($metadata);
    }

    /**
     * Creates a fresh temp backup root, cleaned up when the process ends.
     *
     * @return string Absolute backup storage root
     */
    private function makeRoot(): string
    {
        $root = sys_get_temp_dir() . '/hilos-backup-test-' . uniqid('', true);
        mkdir($root, 0700, true);
        register_shutdown_function(static function () use ($root): void {
            array_map('unlink', glob($root . '/*/*') ?: []);
            array_map('rmdir', glob($root . '/*') ?: []);
            @rmdir($root);
        });

        return $root;
    }

    /**
     * @param string $root Backup storage root
     * @param BackupMetadata $metadata Sidecar payload to write
     * @return string Sidecar path
     */
    private function writeSidecar(string $root, BackupMetadata $metadata): string
    {
        $scopeDir = $root . '/' . $metadata->scope->value;
        if (!is_dir($scopeDir)) {
            mkdir($scopeDir, 0700, true);
        }
        $path = $scopeDir . '/' . BackupCreator::archiveBaseName($metadata->id, $metadata->env, $metadata->scope) . '.json';
        file_put_contents($path, json_encode($metadata->toArray()));

        return $path;
    }

    /**
     * @param string $root Backup storage root
     * @param BackupMetadata $metadata Sidecar to locate and re-read
     * @return BackupMetadata Reloaded sidecar metadata
     */
    private function readSidecar(string $root, BackupMetadata $metadata): BackupMetadata
    {
        $path = $root . '/' . $metadata->scope->value . '/'
            . BackupCreator::archiveBaseName($metadata->id, $metadata->env, $metadata->scope) . '.json';

        return BackupMetadata::fromArray(json_decode((string)file_get_contents($path), true));
    }

    /**
     * @param ?string $socket Unix socket path
     * @param string $password Database password
     * @return DatabaseConnectionConfig Connection settings for rendering tests
     */
    private function config(?string $socket = null, string $password = 'secret'): DatabaseConnectionConfig
    {
        return new DatabaseConnectionConfig(
            host: 'db-host',
            user: 'dumper',
            password: $password,
            database: 'hilos_demo',
            port: 3307,
            charset: 'utf8mb4',
            socket: $socket,
            reconnectAttempts: 1,
            reconnectDelay: 0,
        );
    }
}
