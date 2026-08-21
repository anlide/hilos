<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\BackupVerifyCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the operator archive-verification command.
 *
 * The command is exercised over a real storage tree (sidecars plus archives in a temp
 * backup root), because its whole job is to compare what a sidecar promises with what the
 * filesystem holds. Nothing is asked of a daemon: the sweep stamps its verdict into the
 * sidecar and stops there, and the daemon picks the rewrite up by watching storage (HIL-528).
 */
final class BackupVerifyCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private string $root = '';

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('APP_ENV=test');

        $this->root = sys_get_temp_dir() . '/hilos-verify-cli-' . uniqid('', true);
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
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
        putenv('BACKUP_DIR');
        parent::tearDown();
    }

    public function testAnIntactArchiveVerifiesAndTheVerdictLandsInTheSidecar(): void
    {
        $this->storeBackup('b1', BackupScope::FULL, 'archive payload');

        $output = $this->runCommand();

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringContainsString('checked 1: ok 1, mismatch 0, skipped 0', $output['text']);
        // Silence is the outcome (HIL-528): the sweep stamps the sidecar and says nothing about
        // a daemon. It used to apologize for a poke that had not landed, and the operator read
        // a warning on a run that had gone perfectly.
        $this->assertStringNotContainsString('warning', $output['text']);

        // Verification leaves a trace, or the admin list could never show when a backup was checked.
        $stored = $this->storedSidecar('b1', BackupScope::FULL);
        $this->assertSame(BackupVerifyOutcome::OK, $stored->verifyOutcome);
        $this->assertNotNull($stored->verifiedAt);
        // Stamping must not disturb anything else in the sidecar.
        $this->assertSame(strlen('archive payload'), $stored->sizeBytes);
    }

    public function testACorruptedArchiveIsReportedAndFailsTheRun(): void
    {
        $this->storeBackup('b1', BackupScope::FULL, 'archive payload');
        file_put_contents($this->archivePath('b1', BackupScope::FULL), 'archive paylOad');

        $output = $this->runCommand();

        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString('mismatch', $output['text']);
        $this->assertStringContainsString('expected', $output['text']);
        $this->assertSame(
            BackupVerifyOutcome::MISMATCH,
            $this->storedSidecar('b1', BackupScope::FULL)->verifyOutcome,
        );
    }

    public function testAMissingDigestIsSkippedNotFailed(): void
    {
        // A backup written before checksums existed: nothing to check, and a green exit.
        $this->storeBackup('legacy', BackupScope::FULL, 'archive payload', digest: false);

        $output = $this->runCommand();

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringContainsString('checked 1: ok 0, mismatch 0, skipped 1', $output['text']);
        // Nothing was concluded about the archive, so nothing is stamped into the sidecar.
        $this->assertNull($this->storedSidecar('legacy', BackupScope::FULL)->verifyOutcome);
    }

    public function testAMissingArchiveFailsTheRun(): void
    {
        $this->storeBackup('gone', BackupScope::FULL, 'archive payload');
        unlink($this->archivePath('gone', BackupScope::FULL));

        $output = $this->runCommand();

        // The sidecar promises a file storage no longer delivers; an operator must not read
        // that as green just because nothing mismatched.
        $this->assertSame(ExitCode::ERROR, $output['code']);
        $this->assertStringContainsString(BackupVerifyOutcome::ARCHIVE_MISSING->value, $output['text']);
    }

    public function testAnotherBackupsMissingArchiveDoesNotFailARunAskedForThisOne(): void
    {
        // Ids that share a dash-boundary prefix: matching the anomaly by file name attributed
        // nightly-2's missing archive to a run asked for nightly, failing a healthy backup.
        $this->storeBackup('nightly', BackupScope::FULL, 'one');
        $this->storeBackup('nightly-2', BackupScope::FULL, 'two');
        unlink($this->archivePath('nightly-2', BackupScope::FULL));

        $output = $this->runCommand(args: ['nightly']);

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringNotContainsString(BackupVerifyOutcome::ARCHIVE_MISSING->value, $output['text']);
        $this->assertStringContainsString('checked 1: ok 1, mismatch 0, skipped 0, unverified 0', $output['text']);
    }

    public function testAnUnknownIdIsStillAnArgumentErrorWhenAnotherBackupIsBroken(): void
    {
        // A phantom sidecar must not be mistaken for the id the operator asked about, or the
        // documented exit 2 silently turns into a corruption report about someone else.
        $this->storeBackup('nightly', BackupScope::FULL, 'one');
        unlink($this->archivePath('nightly', BackupScope::FULL));

        $output = $this->runCommand(args: ['night']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $output['code']);
    }

    public function testAMissingArchiveIsCountedAsUnverifiedNotSkipped(): void
    {
        // "skipped" means "no digest, nothing to check" and is explicitly not an error; a
        // vanished archive is the opposite, and the summary must not use the same word for both.
        $this->storeBackup('gone', BackupScope::FULL, 'archive payload');
        unlink($this->archivePath('gone', BackupScope::FULL));

        $output = $this->runCommand();

        $this->assertStringContainsString('checked 1: ok 0, mismatch 0, skipped 0, unverified 1', $output['text']);
    }

    public function testTheScopeOptionNarrowsTheSweep(): void
    {
        $this->storeBackup('b1', BackupScope::FULL, 'full payload');
        $this->storeBackup('b2', BackupScope::SCHEMA_ONLY, 'schema payload');

        $output = $this->runCommand(options: [BackupConstants::SCOPE_OPTION => 'schema-only']);

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringContainsString('checked 1: ok 1', $output['text']);
        $this->assertStringContainsString('b2', $output['text']);
        $this->assertStringNotContainsString('b1 ', $output['text']);
    }

    public function testAnIdChecksOnlyThatBackup(): void
    {
        $this->storeBackup('b1', BackupScope::FULL, 'one');
        $this->storeBackup('b2', BackupScope::FULL, 'two');

        $output = $this->runCommand(args: ['b2']);

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringContainsString('checked 1: ok 1', $output['text']);
    }

    public function testAnUnknownIdAndAnUnknownScopeAreArgumentErrors(): void
    {
        $this->storeBackup('b1', BackupScope::FULL, 'one');

        $unknownId = $this->runCommand(args: ['nope']);
        $this->assertSame(ExitCode::INVALID_ARGUMENT, $unknownId['code']);

        $unknownScope = $this->runCommand(options: [BackupConstants::SCOPE_OPTION => 'nonsense']);
        $this->assertSame(ExitCode::INVALID_ARGUMENT, $unknownScope['code']);

        // A bad argument must not touch storage on its way out.
        $this->assertNull($this->storedSidecar('b1', BackupScope::FULL)->verifyOutcome);
    }

    public function testAnEmptyStoreIsNotAFailure(): void
    {
        $output = $this->runCommand();

        $this->assertSame(ExitCode::SUCCESS, $output['code']);
        $this->assertStringContainsString('No stored backups', $output['text']);
    }

    public function testTheCommandIsAnOperatorCommandNotATestFixture(): void
    {
        $command = new BackupVerifyCommand();

        $this->assertSame(CliCommands::BACKUP_VERIFY, $command->getName());
        // The sibling backup commands refuse on a production-like environment. This one must not:
        // checking a production archive is the entire point, and inheriting that refusal would
        // leave the operator with no way to ask the question at all.
        $this->assertNotInstanceOf(TestOnlyCommand::class, $command);
    }

    /**
     * Runs the command with captured output.
     *
     * Named runCommand(), not run(): PHPUnit's TestCase::run() is final.
     *
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional args
     * @return array{code: int, text: string} Exit code and captured output
     */
    private function runCommand(array $options = [], array $args = []): array
    {
        ob_start();
        $code = new BackupVerifyCommand()->execute($options, $args);

        return ['code' => $code, 'text' => (string)ob_get_clean()];
    }

    /**
     * Writes one stored backup: an archive plus the sidecar that describes it.
     *
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope (also the storage subdirectory)
     * @param string $content Archive bytes
     * @param bool $digest Whether the sidecar records the archive's digest
     */
    private function storeBackup(string $id, BackupScope $scope, string $content, bool $digest = true): void
    {
        $scopeDir = $this->root . '/' . $scope->value;
        if (!is_dir($scopeDir)) {
            mkdir($scopeDir, 0700, true);
        }

        $archivePath = $this->archivePath($id, $scope);
        file_put_contents($archivePath, $content);

        $metadata = new BackupMetadata(
            id: $id,
            createdAt: '2026-08-02T03:00:00+00:00',
            env: 'test',
            scope: $scope,
            connections: [],
            sizeBytes: strlen($content),
            durationSeconds: 4,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: $digest ? (string)hash_file(BackupVerifier::DIGEST_ALGO, $archivePath) : null,
        );
        file_put_contents($this->sidecarPath($id, $scope), json_encode($metadata->toArray()));
    }

    /**
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope
     * @return string Archive path inside the temp backup root
     */
    private function archivePath(string $id, BackupScope $scope): string
    {
        return $this->root . '/' . $scope->value . '/'
            . BackupCreator::archiveBaseName($id, 'test', $scope) . '.tar.gz';
    }

    /**
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope
     * @return string Sidecar path inside the temp backup root
     */
    private function sidecarPath(string $id, BackupScope $scope): string
    {
        return $this->root . '/' . $scope->value . '/'
            . BackupCreator::archiveBaseName($id, 'test', $scope) . '.json';
    }

    /**
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope
     * @return BackupMetadata Sidecar as it now stands on disk
     */
    private function storedSidecar(string $id, BackupScope $scope): BackupMetadata
    {
        return BackupMetadata::fromArray(
            json_decode((string)file_get_contents($this->sidecarPath($id, $scope)), true),
        );
    }
}
