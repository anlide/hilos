<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\BackupException;
use Hilos\Database\DatabaseConnectionConfig;
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
