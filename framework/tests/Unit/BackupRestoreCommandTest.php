<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

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

    /**
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @return ?CommandReplyDTO Next canned reply, or null when none remain
     */
    protected function sendCommand(string $command, array $payload): ?CommandReplyDTO
    {
        $this->sent[] = ['command' => $command, 'payload' => $payload];

        return array_shift($this->replies);
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
    private ?EnvAccessor $previousEnv = null;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('APP_ENV=test');

        $this->root = sys_get_temp_dir() . '/hilos-restore-cli-' . uniqid('', true);
        mkdir($this->root, 0700, true);
        putenv('BACKUP_DIR=' . $this->root);
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

        Hilos::$env = $this->previousEnv;
        putenv('APP_ENV');
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

    public function testProdArchiveIntoTestEnvironmentIsRefusedForAnonymization(): void
    {
        $this->storeBackup('b1', 'archive payload', archiveEnv: 'prod');

        $output = $this->runCommand(args: ['b1'], options: [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('HIL-275', $output['text']);
    }

    public function testNonProdArchiveIntoProdIsRefused(): void
    {
        $this->storeBackup('b1', 'archive payload');
        putenv('APP_ENV=prod');

        $output = $this->runCommand(args: ['b1'], options: [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('must not overwrite production', $output['text']);
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
        $this->assertStringContainsString('--cold', $output['text']);
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
        $this->assertStringContainsString('Restore runtime row is not mounted', $output['text']);
        $this->assertStringNotContainsString('stopped answering', $output['text']);
    }

    public function testBusyAgentRefusalIsReportedVerbatim(): void
    {
        $this->storeBackup('b1', 'archive payload');
        $probe = new BackupRestoreCommandProbe();
        $probe->replies = [CommandReplyDTO::error('r1', 'Backup subsystem busy: 2026-08-08_03-00-00')];

        $output = $this->runCommand($probe, ['b1'], [BackupConstants::YES_OPTION => true]);

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('busy', $output['text']);
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
     * Stores one fixture backup (archive + digested sidecar) in the temp root.
     *
     * @param string $id Backup id
     * @param string $content Archive bytes
     * @param string $archiveEnv Environment recorded in the sidecar
     */
    private function storeBackup(string $id, string $content, string $archiveEnv = 'test'): void
    {
        $scopeDir = $this->root . '/' . BackupScope::FULL->value;
        if (!is_dir($scopeDir)) {
            mkdir($scopeDir, 0700, true);
        }

        $archivePath = $this->archivePath($id, $archiveEnv);
        file_put_contents($archivePath, $content);

        $metadata = new BackupMetadata(
            id: $id,
            createdAt: '2026-08-08T03:00:00+00:00',
            env: $archiveEnv,
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: strlen($content),
            durationSeconds: 4,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: (string)hash_file(BackupVerifier::DIGEST_ALGO, $archivePath),
        );
        file_put_contents(
            $scopeDir . '/' . BackupCreator::archiveBaseName($id, $archiveEnv, BackupScope::FULL)
            . BackupCreator::SIDECAR_EXTENSION,
            json_encode($metadata->toArray()),
        );
    }

    /**
     * @param string $id Backup id
     * @param string $archiveEnv Environment in the stored base name
     * @return string Archive path inside the temp backup root
     */
    private function archivePath(string $id, string $archiveEnv = 'test'): string
    {
        return $this->root . '/' . BackupScope::FULL->value . '/'
            . BackupCreator::archiveBaseName($id, $archiveEnv, BackupScope::FULL)
            . BackupCreator::ARCHIVE_EXTENSION;
    }
}
