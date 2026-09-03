<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Backup\Exception\BackupException;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;
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
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        parent::setUp();
        // recordFailure reads BACKUP_DIR (storage root) and APP_ENV (sidecar base name) off the
        // env facade; a stub-backed accessor lets the failure-record tests run without a live env.
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('APP_ENV=test');
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
        putenv('BACKUP_DIR');
        parent::tearDown();
    }

    public function testABackupIsDatedByItsStartNotItsFinish(): void
    {
        // The id is minted when the run starts, so the record must read the same instant back:
        // dating by "now" would put a long dump's finish time on a row whose id says otherwise.
        // Built through the same default timezone the supervisor mints ids in, so the case does
        // not depend on where the suite runs.
        $expected = new DateTimeImmutable('2026-07-19 10:30:00')->format(DateTimeInterface::ATOM);

        $this->assertSame($expected, BackupCreator::startedAtFromId('2026-07-19_10-30-00'));
    }

    public function testAMalformedIdStillYieldsADate(): void
    {
        $this->assertNotSame('', BackupCreator::startedAtFromId('not-a-timestamp'));
    }

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

    public function testBothSchemaScopesStampTheMigrationLevelIntoTheDump(): void
    {
        // Both are broken the same way - the `migration` table is dumped without its rows - so
        // both need the level to travel in the text instead.
        $this->assertSame(7, BackupCreator::scopeMarkerIndex(BackupScope::SCHEMA_ONLY, 7));
        $this->assertSame(7, BackupCreator::scopeMarkerIndex(BackupScope::SCHEMA_SEED, 7));
    }

    public function testAFullScopeDumpIsNotStamped(): void
    {
        // FULL dumps the rows of `migration` itself; a stamp there would be a second copy of the
        // same number in the same file, free to disagree with the rows next to it.
        $this->assertNull(BackupCreator::scopeMarkerIndex(BackupScope::FULL, 7));
    }

    public function testALevelZeroSchemaDumpIsStillStamped(): void
    {
        // "Never migrated" is a level, and the restore refusal stands on telling it from
        // "this archive says nothing".
        $this->assertSame(0, BackupCreator::scopeMarkerIndex(BackupScope::SCHEMA_ONLY, 0));
    }

    public function testMeasureWorkDirSumsTheDumpFilesAndIgnoresSubdirectories(): void
    {
        // A successful run records a non-zero dumpBytes: the sum of the dump files in the work dir
        // (db-*.sql plus the in-archive metadata copy), the uncompressed peak the space guard sizes
        // runs from. The full create path needs a live database and is exercised at e2e; here the
        // measurement itself is pinned over a hand-built work dir.
        $workDir = sys_get_temp_dir() . '/hilos-measure-' . uniqid('', true);
        mkdir($workDir . '/nested', 0700, true);
        file_put_contents($workDir . '/db-0.sql', str_repeat('a', 1000));
        file_put_contents($workDir . '/metadata.json', str_repeat('b', 24));
        file_put_contents($workDir . '/nested/ignored.sql', str_repeat('c', 5000));

        try {
            // 1000 + 24; the subdirectory and its contents are not counted.
            $this->assertSame(1024, BackupCreator::measureWorkDir($workDir));
            $this->assertSame(0, BackupCreator::measureWorkDir($workDir . '/does-not-exist'));
        } finally {
            @unlink($workDir . '/db-0.sql');
            @unlink($workDir . '/metadata.json');
            @unlink($workDir . '/nested/ignored.sql');
            @rmdir($workDir . '/nested');
            @rmdir($workDir);
        }
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

        new BackupCreator()->create('../escape', BackupScope::FULL);
    }

    public function testRecordFailurePublishesAnErrorSidecarCarryingTheReason(): void
    {
        $root = $this->makeRoot();
        putenv('BACKUP_DIR=' . $root);

        new BackupCreator()->recordFailure(
            '2026-07-19_10-30-00',
            BackupScope::FULL,
            5,
            'child exited with code 2: mysqldump: connection refused',
        );

        $sidecar = $this->readErrorSidecar($root, '2026-07-19_10-30-00', BackupScope::FULL);
        $this->assertSame(BackupStatus::ERROR, $sidecar->status);
        $this->assertSame(5, $sidecar->durationSeconds);
        $this->assertSame(
            'child exited with code 2: mysqldump: connection refused',
            $sidecar->failureReason,
        );
    }

    public function testRecordFailureCapsALongReasonToTheLimitWithAnEllipsis(): void
    {
        $root = $this->makeRoot();
        putenv('BACKUP_DIR=' . $root);

        // A killed dump's stderr can be a wall of text; the tail is cut past the 2000-char cap.
        new BackupCreator()->recordFailure('2026-07-19_10-30-01', BackupScope::FULL, 1, str_repeat('x', 2500));

        $reason = $this->readErrorSidecar($root, '2026-07-19_10-30-01', BackupScope::FULL)->failureReason;
        $this->assertNotNull($reason);
        $this->assertSame(2000, mb_strlen($reason));
        $this->assertStringEndsWith('…', $reason);
    }

    public function testRecordFailureStoresNullWhenThereIsNoReason(): void
    {
        $root = $this->makeRoot();
        putenv('BACKUP_DIR=' . $root);

        new BackupCreator()->recordFailure('2026-07-19_10-30-02', BackupScope::FULL, 0, null);
        new BackupCreator()->recordFailure('2026-07-19_10-30-03', BackupScope::SCHEMA_ONLY, 0, '   ');

        // A blank reason is null, not an empty string, so a reader tells "no detail" apart.
        $this->assertNull($this->readErrorSidecar($root, '2026-07-19_10-30-02', BackupScope::FULL)->failureReason);
        $this->assertNull(
            $this->readErrorSidecar($root, '2026-07-19_10-30-03', BackupScope::SCHEMA_ONLY)->failureReason,
        );
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

        new BackupCreator()->setStoredKeep($this->rowFor($original), $root, true);

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

        new BackupCreator()->setStoredKeep($this->rowFor($original), $root, false);

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
        new BackupCreator()->setStoredKeep($this->rowFor($original), $root, true);

        clearstatcache();
        $this->assertSame($mtimeBefore, filemtime($path));
        $this->assertTrue($this->readSidecar($root, $original)->keep);
    }

    public function testSetStoredKeepThrowsWhenTheSidecarIsMissing(): void
    {
        $this->expectException(BackupException::class);

        new BackupCreator()->setStoredKeep($this->rowFor($this->metadata()), $this->makeRoot(), true);
    }

    public function testRecordVerificationStampsTheSidecarAndKeepsTheDigest(): void
    {
        $root = $this->makeRoot();
        $original = new BackupMetadata(
            id: 'bk1',
            createdAt: '2026-08-02T03:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 12,
            keep: true,
            status: BackupStatus::SUCCESS,
            sha256: str_repeat('ab', 32),
        );
        $this->writeSidecar($root, $original);

        new BackupCreator()->recordVerification(
            $original,
            $root,
            BackupVerifyOutcome::MISMATCH,
            '2026-08-02T06:00:00+00:00',
        );

        $reloaded = $this->readSidecar($root, $original);
        $this->assertSame('2026-08-02T06:00:00+00:00', $reloaded->verifiedAt);
        $this->assertSame(BackupVerifyOutcome::MISMATCH, $reloaded->verifyOutcome);
        // The verification stamp is the only change: the digest it judged and the keep pin stay put.
        $this->assertSame($original->sha256, $reloaded->sha256);
        $this->assertTrue($reloaded->keep);
        $this->assertSame(4096, $reloaded->sizeBytes);
    }

    public function testRecordVerificationThrowsWhenTheSidecarIsMissing(): void
    {
        $this->expectException(BackupException::class);

        new BackupCreator()->recordVerification(
            $this->metadata(),
            $this->makeRoot(),
            BackupVerifyOutcome::OK,
            '2026-08-02T06:00:00+00:00',
        );
    }

    public function testRecordShippingStampsTheSidecarAndLeavesEverythingElseAlone(): void
    {
        $root = $this->makeRoot();
        $original = new BackupMetadata(
            id: 'bk-ship',
            createdAt: '2026-08-16T03:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 4096,
            durationSeconds: 12,
            keep: true,
            status: BackupStatus::SUCCESS,
            sha256: str_repeat('ab', 32),
        );
        $this->writeSidecar($root, $original);

        $stored = new BackupCreator()->recordShipping(
            $this->rowFor($original),
            $root,
            '2026-08-16T06:00:00+00:00',
            BackupShipOutcome::OK,
            null,
            'f0e1d2c3b4a5',
        );

        $this->assertNull($stored);
        $reloaded = $this->readSidecar($root, $original);
        $this->assertSame('2026-08-16T06:00:00+00:00', $reloaded->shippedAt);
        $this->assertSame(BackupShipOutcome::OK, $reloaded->shipOutcome);
        $this->assertNull($reloaded->shipError);
        // The shape the copy left in is stamped beside the outcome: it is what tells a later pass
        // that the recipients have not changed since.
        $this->assertSame('f0e1d2c3b4a5', $reloaded->shipEncryption);
        // The shipping stamp is the only change: the digest, the pin, and the size stay put.
        $this->assertSame($original->sha256, $reloaded->sha256);
        $this->assertTrue($reloaded->keep);
        $this->assertSame(4096, $reloaded->sizeBytes);
    }

    public function testRecordShippingCapsTheErrorAndHandsBackWhatItStored(): void
    {
        // The error carries a killed transfer's stderr, which can be a wall of text. The caller
        // records the returned value on the index row, so both halves say the same thing.
        $root = $this->makeRoot();
        $original = $this->metadata();
        $this->writeSidecar($root, $original);

        $stored = new BackupCreator()->recordShipping(
            $this->rowFor($original),
            $root,
            null,
            BackupShipOutcome::FAILED,
            str_repeat('x', 5000),
            null,
        );

        $reloaded = $this->readSidecar($root, $original);
        $this->assertNotNull($stored);
        $this->assertSame($stored, $reloaded->shipError);
        $this->assertLessThan(5000, mb_strlen($stored));
        $this->assertStringEndsWith('…', $stored);
        $this->assertNull($reloaded->shippedAt);
        $this->assertSame(BackupShipOutcome::FAILED, $reloaded->shipOutcome);
    }

    public function testRecordShippingThrowsWhenTheSidecarIsMissing(): void
    {
        $this->expectException(BackupException::class);

        new BackupCreator()->recordShipping(
            $this->rowFor($this->metadata()),
            $this->makeRoot(),
            null,
            BackupShipOutcome::FAILED,
            'unreachable',
            null,
        );
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
        $state = StateBackupHistory::fromMetadata($metadata);

        return new BackupHistory($state);
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
     * Reads back the error sidecar recordFailure published under the test env.
     *
     * @param string $root Backup storage root
     * @param string $id Backup id the failure was recorded under
     * @param BackupScope $scope Scope of the failed run
     * @return BackupMetadata Reloaded error sidecar metadata
     */
    private function readErrorSidecar(string $root, string $id, BackupScope $scope): BackupMetadata
    {
        $path = $root . '/' . $scope->value . '/' . BackupCreator::archiveBaseName($id, 'test', $scope) . '.json';

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
