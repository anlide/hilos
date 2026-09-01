<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\BackupRestoreCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Migration;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Command-channel double: replays canned replies and records what was sent.
 *
 * The daemon round-trip is the one seam this suite must not cross (no daemon runs
 * here), so the trait method is overridden at exactly that boundary; everything
 * above it - preflight, dispatch, monitor - runs for real.
 */
final class BackupRestoreCommandProbe extends BackupRestoreCommand
{
    /** @var list<array{command: string, payload: array<string, mixed>}> */
    public array $sent = [];

    /** @var list<?CommandReplyDTO> Replies consumed in order; empty means "daemon silent" */
    public array $replies = [];

    /** @var list<string> Sentences the command wrote to stderr, in order */
    public array $printedToStandardError = [];

    /** Address the canned round-trip reports itself as having used. */
    private const string ADDRESS = '127.0.0.1:8094';

    /**
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @return CommandChannelResult Next canned reply, or an unreachable channel when none remain
     */
    protected function sendCommand(string $command, array $payload): CommandChannelResult
    {
        $this->sent[] = ['command' => $command, 'payload' => $payload];

        $reply = array_shift($this->replies);

        return $reply === null
            ? CommandChannelResult::unreachable(self::ADDRESS)
            : CommandChannelResult::replied($reply, self::ADDRESS);
    }

    /**
     * Records what would have gone to stderr, which no output buffer can read back.
     *
     * The stream is the one thing about a refusal the process cannot show a test - `ob_start()`
     * captures stdout and nothing else - so the double stands in for it here, the same way it
     * stands in for the command channel above. The sentence and the exit code stay the real
     * ones: what is replaced is the writing, not the wording.
     *
     * @param string $text Sentence the command wrote
     * @return int Exit code to return from the command
     */
    protected function printToStandardError(string $text): int
    {
        $this->printedToStandardError[] = $text;

        return ExitCode::ERROR;
    }
}

/**
 * Unit tests for the restore CLI's preflight, dispatch, and monitor.
 *
 * Storage fixtures are real files in a temp backup root (the digest gate hashes them for
 * real); the hot path talks to a canned command channel; the cold path runs the real
 * engine, whose extract step fails on the plain-text fixture - proving the engine was
 * entered without needing a database in the unit suite.
 */
final class BackupRestoreCommandTest extends TestCase
{
    /** Migration track the fixture files are written under. */
    private const string MIGRATION_TRACK = 'main';

    private ?EnvAccessor $previousEnv = null;

    private string $root = '';

    private string $migrationRoot = '';

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('APP_ENV=test');

        $this->root = sys_get_temp_dir() . '/hilos-restore-cli-' . uniqid('', true);
        mkdir($this->root, 0700, true);
        putenv('BACKUP_DIR=' . $this->root);

        // The migration gate reads the code's level off disk, so a test that wants a level
        // has to give it files. Pointed at an empty tree by default: no migrations listed,
        // which is what every pre-existing case in this suite ran with.
        $this->migrationRoot = sys_get_temp_dir() . '/hilos-restore-cli-migrations-' . uniqid('', true);
        Migration::setMigrationListPath($this->migrationRoot);
        Migration::setMigrationName(self::MIGRATION_TRACK);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $path) {
            unlink($path);
        }
        foreach (glob($this->root . '/*') ?: [] as $path) {
            rmdir($path);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
        // The path stays set on the static Migration for the rest of the process; removing the
        // tree is what makes it harmless - an unreadable path lists no migrations, the state
        // this suite found.
        $this->removeTree($this->migrationRoot);

        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
        putenv('BACKUP_DIR');
        parent::tearDown();
    }

    public function testUnconfiguredBackupDirIsAConfigError(): void
    {
        putenv('BACKUP_DIR');

        $output = $this->runCommand(args: ['b1'], options: [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::CONFIG_ERROR, $output['code']);
        $this->assertStringContainsString('BACKUP_DIR', $output['text']);
    }

    public function testUnknownBackupIdIsAnInvalidArgument(): void
    {
        $output = $this->runCommand(args: ['nope'], options: [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $output['code']);
        $this->assertStringContainsString('No stored backup', $output['text']);
    }

    public function testCorruptedArchiveIsRefusedBeforeAnyDispatch(): void
    {
        $this->storeBackup('b1', 'archive payload');
        file_put_contents($this->archivePath('b1'), 'archive paylOad');

        $probe = new BackupRestoreCommandProbe();
        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('verification', $output['text']);
        $this->assertSame([], $probe->sent, 'A failed digest check must never reach the daemon');
    }

    public function testProdArchiveIntoTestEnvironmentPassesThePiiPreflightOnTheFrameworkRows(): void
    {
        $this->storeBackup('b1', 'archive payload', archiveEnv: 'prod');

        $probe = new BackupRestoreCommandProbe();
        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        // The ENV guard demands anonymization, and this installation mounts no collection of
        // its own - yet the collected registry is not empty, because the framework classifies
        // the tables it ships (HIL-585). So the preflight has nothing to refuse on and the
        // run reaches the daemon; what an installation failed to classify is caught later by
        // the coverage gate, which refuses before the first import and names the tables.
        $this->assertStringNotContainsString(Entity::META_PII, $output['text']);
        $this->assertNotSame([], $probe->sent, 'A declared registry must not hold the run back');
        // The canned channel answers nothing, so the run ends on the silent-daemon path.
        $this->assertSame(ExitCode::ERROR, $output['code']);
    }

    public function testASchemaOnlyArchiveNeedsNoPiiRegistry(): void
    {
        $this->storeBackup('b1', 'archive payload', archiveEnv: 'prod', scope: BackupScope::SCHEMA_ONLY);

        $probe = new BackupRestoreCommandProbe();
        $output = $this->runCommand($probe, ['b1'], [
            BackupConstants::YES_OPTION => true,
            BackupConstants::SCOPE_OPTION => BackupScope::SCHEMA_ONLY->value,
        ]);

        // Same ENV verdict as the case above, and the same missing registry - but this archive
        // carries no rows, so the engine skips the pass and the preflight has nothing to demand.
        $this->assertNotSame(ExitCode::CONFIG_ERROR, $output['code']);
        $this->assertStringNotContainsString(Entity::META_PII, $output['text']);
        $this->assertNotSame([], $probe->sent, 'A schema-only restore must reach the daemon');
    }

    public function testNonProdArchiveIntoProdIsRefused(): void
    {
        $this->storeBackup('b1', 'archive payload');
        putenv('APP_ENV=prod');

        $output = $this->runCommand(args: ['b1'], options: [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('must not overwrite production', $output['text']);
    }

    public function testArchiveAheadOfTheCodeIsRefusedBeforeTheDigestAndBeforeYes(): void
    {
        $this->listCodeMigrations(1, 2, 3);
        $this->storeBackup('b1', 'archive payload', connections: [new BackupConnectionMeta(0, 'db', 5)]);
        // Corrupted on purpose, and --yes withheld: whichever of those two the preflight
        // reached first would answer instead of the gate.
        file_put_contents($this->archivePath('b1'), 'archive paylOad');
        $probe = new BackupRestoreCommandProbe();

        $output = $this->runCommand($probe, ['b1']);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('archive at migration 5, code expects 3 (2 ahead)', $output['text']);
        $this->assertStringContainsString('no downgrade path', $output['text']);
        $this->assertStringNotContainsString('verification', $output['text']);
        $this->assertStringNotContainsString('--yes', $output['text']);
        $this->assertSame([], $probe->sent, 'A refused restore must never reach the daemon');
    }

    public function testForceDoesNotLiftTheRefusalOfAnArchiveAheadOfTheCode(): void
    {
        $this->listCodeMigrations(1, 2, 3);
        $this->storeBackup('b1', 'archive payload', connections: [new BackupConnectionMeta(0, 'db', 5)]);

        $output = $this->runCommand(args: ['b1'], options: [
            BackupConstants::YES_OPTION => true,
            BackupConstants::FORCE_OPTION => true,
        ]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('no downgrade path', $output['text']);
    }

    public function testArchiveBehindTheCodeAnnouncesTheMigrationsAndStillDispatches(): void
    {
        $this->listCodeMigrations(1, 2, 3);
        $this->storeBackup('b1', 'archive payload', connections: [new BackupConnectionMeta(0, 'db', 1)]);
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [
            CommandReplyDTO::ok('r1'),
            CommandReplyDTO::ok('r2', [
                BackupConstants::FIELD_RESTORE_RUNNING => false,
                BackupConstants::FIELD_RESTORE_OUTCOME => BackupStatus::SUCCESS->value,
            ]),
        ];

        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringContainsString('connection 0: archive at migration 1, code expects 3', $output['text']);
        $this->assertStringContainsString('2 migration(s) will be applied after the import', $output['text']);
        $this->assertSame(BackupConstants::RESTORE_REQUEST_COMMAND, $probe->sent[0]['command']);
    }

    public function testAMigrationIndexOnAFullArchiveIsAnArgumentError(): void
    {
        // Not a silent no-op: a FULL archive carries its level in the rows it imports, and an
        // operator who named a number must learn it meant nothing.
        $probe = new BackupRestoreCommandProbe();
        $this->storeBackup('b1', 'archive payload');

        $output = $this->runCommand($probe, ['b1'], [
            BackupConstants::MIGRATION_INDEX_OPTION => '3',
            BackupConstants::YES_OPTION => true,
        ]);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $output['code']);
        $this->assertStringContainsString('schema archives only', $output['text']);
        $this->assertSame([], $probe->sent, 'A rejected argument must never reach the daemon');
    }

    public function testANonIntegerMigrationIndexIsAnArgumentError(): void
    {
        // Refused as text rather than cast: a cast turns every wrong answer into level 0, which
        // is a level a schema archive may legitimately be restored at.
        $this->storeBackup('b1', 'archive payload', scope: BackupScope::SCHEMA_ONLY);

        $output = $this->runCommand(args: ['b1'], options: [
            BackupConstants::SCOPE_OPTION => BackupScope::SCHEMA_ONLY->value,
            BackupConstants::MIGRATION_INDEX_OPTION => 'latest',
            BackupConstants::YES_OPTION => true,
        ]);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $output['code']);
        $this->assertStringContainsString('an integer of 0 or more', $output['text']);
    }

    public function testTheOperatorsMigrationIndexTravelsOnTheRestoreRequest(): void
    {
        $this->storeBackup('b1', 'archive payload', scope: BackupScope::SCHEMA_ONLY);
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [
            CommandReplyDTO::ok('r1', [BackupConstants::FIELD_BACKUP_ID => 'b1']),
            CommandReplyDTO::ok('r2', [
                BackupConstants::FIELD_RESTORE_RUNNING => false,
                BackupConstants::FIELD_RESTORE_OUTCOME => BackupStatus::SUCCESS->value,
            ]),
        ];

        $output = $this->runCommand($probe, ['b1'], [
            BackupConstants::SCOPE_OPTION => BackupScope::SCHEMA_ONLY->value,
            BackupConstants::MIGRATION_INDEX_OPTION => '3',
            BackupConstants::YES_OPTION => true,
        ]);

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertSame(3, $probe->sent[0]['payload'][BackupConstants::FIELD_MIGRATION_INDEX]);
    }

    public function testARestoreWithoutTheOptionCarriesNoMigrationIndexKey(): void
    {
        // The key's absence is the message the agent reads: a run in which nobody named a level
        // must build a child argv without the option, not one carrying a null.
        $this->storeBackup('b1', 'archive payload', scope: BackupScope::SCHEMA_ONLY);
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [
            CommandReplyDTO::ok('r1', [BackupConstants::FIELD_BACKUP_ID => 'b1']),
            CommandReplyDTO::ok('r2', [
                BackupConstants::FIELD_RESTORE_RUNNING => false,
                BackupConstants::FIELD_RESTORE_OUTCOME => BackupStatus::SUCCESS->value,
            ]),
        ];

        $this->runCommand($probe, ['b1'], [
            BackupConstants::SCOPE_OPTION => BackupScope::SCHEMA_ONLY->value,
            BackupConstants::YES_OPTION => true,
        ]);

        $this->assertArrayNotHasKey(BackupConstants::FIELD_MIGRATION_INDEX, $probe->sent[0]['payload']);
    }

    public function testForceDoesNotLiftASchemaArchivesMissingMarker(): void
    {
        // --force is about an unknown environment; the missing level is not a verdict it may
        // overrule, because the option delivers a fact rather than lifting a gate. A real tarball
        // here on purpose: the refusal is only reachable once the archive actually unpacks.
        $this->storeBackup(
            'b1',
            $this->schemaArchiveBytes(),
            connections: [new BackupConnectionMeta(0, 'db', null)],
            scope: BackupScope::SCHEMA_ONLY,
        );
        $probe = new BackupRestoreCommandProbe();

        $output = $this->runCommand($probe, ['b1'], [
            BackupConstants::SCOPE_OPTION => BackupScope::SCHEMA_ONLY->value,
            BackupConstants::YES_OPTION => true,
            BackupConstants::FORCE_OPTION => true,
            BackupConstants::COLD_OPTION => true,
        ]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('records no migration level', $output['text']);
        $this->assertStringContainsString('--' . BackupConstants::MIGRATION_INDEX_OPTION, $output['text']);
        $this->assertStringContainsString('The database was not touched', $output['text']);
    }

    public function testMissingYesIsAnInvalidArgumentAfterAllChecksPass(): void
    {
        $this->storeBackup('b1', 'archive payload');

        $output = $this->runCommand(args: ['b1']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $output['code']);
        $this->assertStringContainsString('--yes', $output['text']);
    }

    public function testHotPathSendsTheRecordedDecisionAndMonitorsToSuccess(): void
    {
        $this->storeBackup('b1', 'archive payload');
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [
            CommandReplyDTO::ok('r1', [BackupConstants::FIELD_BACKUP_ID => 'b1']),
            CommandReplyDTO::ok('r2', [
                BackupConstants::FIELD_RESTORE_RUNNING => false,
                BackupConstants::FIELD_RESTORE_PHASE => RestorePhase::SUCCEEDED->value,
                BackupConstants::FIELD_RESTORE_OUTCOME => BackupStatus::SUCCESS->value,
            ]),
        ];

        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringContainsString('Restore completed', $output['text']);
        $this->assertSame(BackupConstants::RESTORE_REQUEST_COMMAND, $probe->sent[0]['command']);
        $this->assertSame(
            RestoreEnvDecision::ALLOW->value,
            $probe->sent[0]['payload'][BackupConstants::FIELD_DECISION],
            'The agent must receive the decision the preflight recorded',
        );
        $this->assertSame(BackupConstants::RESTORE_STATUS_COMMAND, $probe->sent[1]['command']);
    }

    public function testHotPathReportsAFailedRunWithItsReason(): void
    {
        $this->storeBackup('b1', 'archive payload');
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [
            CommandReplyDTO::ok('r1'),
            CommandReplyDTO::ok('r2', [
                BackupConstants::FIELD_RESTORE_RUNNING => false,
                BackupConstants::FIELD_RESTORE_PHASE => RestorePhase::FAILED->value,
                BackupConstants::FIELD_RESTORE_OUTCOME => BackupStatus::ERROR->value,
                BackupConstants::FIELD_RESTORE_FAILURE => 'import into connection 0 exited with code 1',
            ]),
        ];

        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('import into connection 0', $output['text']);
    }

    public function testSilentDaemonIsAnErrorSuggestingColdNotAFallback(): void
    {
        $this->storeBackup('b1', 'archive payload');
        $probe = new BackupRestoreCommandProbe();

        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertContains('Start the daemon, or restore with --cold', $probe->printedToStandardError);
        $this->assertCount(1, $probe->sent, 'A silent daemon must not be retried into a cold restore');
    }

    public function testDashedNeighbourIdDoesNotMakeAnExactIdAmbiguous(): void
    {
        // 'nightly-2' also matches the glob for 'nightly'; the sidecar id must disambiguate.
        $this->storeBackup('nightly', 'archive payload');
        $this->storeBackup('nightly-2', 'other payload');
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [
            CommandReplyDTO::ok('r1'),
            CommandReplyDTO::ok('r2', [
                BackupConstants::FIELD_RESTORE_RUNNING => false,
                BackupConstants::FIELD_RESTORE_OUTCOME => BackupStatus::SUCCESS->value,
            ]),
        ];

        $output = $this->runCommand($probe, ['nightly'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertSame('nightly', $probe->sent[0]['payload'][BackupConstants::FIELD_BACKUP_ID]);
    }

    public function testMonitorReportsAnErrorReplyInsteadOfCallingItSilence(): void
    {
        $this->storeBackup('b1', 'archive payload');
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [
            CommandReplyDTO::ok('r1'),
            CommandReplyDTO::error('r2', 'Restore runtime row is not mounted'),
        ];

        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertSame(
            ['Refused: Restore runtime row is not mounted', 'The restore may still be running'],
            $probe->printedToStandardError,
        );
        $this->assertStringNotContainsString('stopped answering', $output['text']);
    }

    public function testBusyAgentRefusalIsReportedVerbatim(): void
    {
        $this->storeBackup('b1', 'archive payload');
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [CommandReplyDTO::error('r1', 'Backup subsystem busy: 2026-08-08_03-00-00')];

        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertSame(
            ['Refused: Backup subsystem busy: 2026-08-08_03-00-00'],
            $probe->printedToStandardError,
        );
    }

    public function testColdPathEntersTheRealEngineWithoutTheDaemon(): void
    {
        // The fixture is plain text with a CORRECT digest: the engine passes its verify gate
        // and fails at extract (not a tar), which proves the cold path ran the engine in this
        // process - no request ever left for the daemon.
        $this->storeBackup('b1', 'archive payload');
        $probe = new BackupRestoreCommandProbe();

        $output = $this->runCommand($probe, ['b1'], [
            BackupConstants::YES_OPTION => true,
            BackupConstants::COLD_OPTION => true,
        ]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString(RestorePhase::VERIFYING->value, $output['text']);
        $this->assertStringContainsString(RestorePhase::EXTRACTING->value, $output['text']);
        $this->assertStringContainsString('tar', $output['text']);
        $this->assertSame([], $probe->sent, 'The cold path must never talk to the daemon');
    }

    /**
     * Runs the command capturing its output.
     *
     * Named runCommand(), not run(): PHPUnit's TestCase::run() is final.
     *
     * @param ?BackupRestoreCommandProbe $probe Command double, or null for a fresh one
     * @param list<string> $args Positional args
     * @param array<string, mixed> $options Parsed options
     * @return array{code: int, text: string} Exit code and captured output
     */
    private function runCommand(
        ?BackupRestoreCommandProbe $probe = null,
        array $args = [],
        array $options = [],
    ): array {
        $command = $probe ?? new BackupRestoreCommandProbe();
        ob_start();
        $code = $command->execute($options, $args);

        return ['code' => $code, 'text' => (string)ob_get_clean()];
    }

    /**
     * Builds the bytes of a real schema archive whose dump declares no migration level.
     *
     * The other fixtures here are plain text, which is enough for every case that is answered
     * before the archive is opened. The marker refusal is not one of those: it is reached after
     * extract, so the fixture has to be a tarball the engine can actually unpack.
     *
     * @return string Archive bytes
     */
    private function schemaArchiveBytes(): string
    {
        $workDir = $this->root . '/build';
        mkdir($workDir, 0700, true);
        file_put_contents(
            $workDir . '/' . BackupCreator::SQL_FILE_PREFIX . '0' . BackupCreator::SQL_FILE_SUFFIX,
            "CREATE TABLE `migration` (\n  `index` int NOT NULL,\n  PRIMARY KEY (`index`)\n) ENGINE=InnoDB;\n",
        );

        $tarPath = $this->root . '/build.tar.gz';
        exec(
            'tar -czf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($workDir) . ' .',
            $ignored,
            $exitCode,
        );
        $this->assertSame(0, $exitCode, 'Fixture archive build must succeed');
        $bytes = (string)file_get_contents($tarPath);
        $this->removeTree($workDir);
        unlink($tarPath);

        return $bytes;
    }

    /**
     * Stores one fixture backup (archive + digested sidecar) in the temp root.
     *
     * @param string $id Backup id
     * @param string $content Archive bytes
     * @param string $archiveEnv Environment recorded in the sidecar
     * @param list<BackupConnectionMeta> $connections Connections recorded in the sidecar
     * @param BackupScope $scope Scope the fixture is stored and recorded under
     */
    private function storeBackup(
        string $id,
        string $content,
        string $archiveEnv = 'test',
        array $connections = [],
        BackupScope $scope = BackupScope::FULL,
    ): void {
        $scopeDir = $this->root . '/' . $scope->value;
        if (!is_dir($scopeDir)) {
            mkdir($scopeDir, 0700, true);
        }

        $archivePath = $this->archivePath($id, $archiveEnv, $scope);
        file_put_contents($archivePath, $content);

        $metadata = new BackupMetadata(
            id: $id,
            createdAt: '2026-08-08T03:00:00+00:00',
            env: $archiveEnv,
            scope: $scope,
            connections: $connections,
            sizeBytes: strlen($content),
            durationSeconds: 4,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: (string)hash_file(BackupVerifier::DIGEST_ALGO, $archivePath),
        );
        file_put_contents(
            $scopeDir . '/' . BackupCreator::archiveBaseName($id, $archiveEnv, $scope)
            . BackupCreator::SIDECAR_EXTENSION,
            json_encode($metadata->toArray()),
        );
    }

    /**
     * @param string $id Backup id
     * @param string $archiveEnv Environment in the stored base name
     * @param BackupScope $scope Scope subdirectory the fixture lives under
     * @return string Archive path inside the temp backup root
     */
    private function archivePath(
        string $id,
        string $archiveEnv = 'test',
        BackupScope $scope = BackupScope::FULL,
    ): string {
        return $this->root . '/' . $scope->value . '/'
            . BackupCreator::archiveBaseName($id, $archiveEnv, $scope)
            . BackupCreator::ARCHIVE_EXTENSION;
    }

    /**
     * Gives this code a migration level by writing the up-files the gate counts.
     *
     * The bodies stay empty: nothing here ever runs them, the gate only reads the indices
     * off the file names.
     *
     * @param int ...$indices Migration indices the code lists
     */
    private function listCodeMigrations(int ...$indices): void
    {
        $trackDir = $this->migrationRoot . '/' . self::MIGRATION_TRACK;
        if (!is_dir($trackDir)) {
            mkdir($trackDir, 0700, true);
        }
        foreach ($indices as $index) {
            file_put_contents($trackDir . '/' . $index . '_up.sql', "-- fixture\n");
        }
    }

    /**
     * Recursively removes a fixture tree (best effort).
     *
     * @param string $path Directory path
     */
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
