<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\Ship\BackupShipCommand;
use Hilos\Backup\Ship\BackupShipPlan;
use Hilos\Backup\Ship\BackupShipPlanner;
use Hilos\Backup\Ship\BackupShipStep;
use Hilos\Backup\Ship\BackupShipTarget;
use Hilos\Backup\Ship\BackupShipperFactory;
use Hilos\Backup\Ship\BackupShipperInterface;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToSetStdErrException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessException;
use Hilos\Core\Exception\Process\FailedToWriteStdInException;
use Hilos\Core\Process;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for shipping: a backup really leaving this machine.
 *
 * Everything else about the seam is decided without a link - the planner picks a step from rows
 * and files, the drivers spell a command out as argv, and both are unit-tested that way. What
 * cannot be asserted without a second machine is whether that argv, run for real, puts the pair
 * where the operator was promised it would be and takes it away again when rotation does. So the
 * stand grows a receiver (`sshd-framework-test`) and this test ships to it, the same way the chat
 * stand grew a mail interceptor rather than trusting that an SMTP call would have worked.
 *
 * Configuration goes through {@see Hilos::$env} rather than around it: a deployment activates
 * shipping by writing four values, and a proof that skipped straight to the driver would leave
 * exactly the step operators get wrong.
 */
final class BackupShipperIntegrationTest extends TestCase
{
    /** Receiver service of the framework test stand; also the name its host key is pinned under. */
    private const string RECEIVER_HOST = 'sshd-framework-test';

    /** Unprivileged account the receiver accepts the transfer as. */
    private const string RECEIVER_USER = 'shipper';

    /** Destination root on the receiver; scope directories are created under it by rsync. */
    private const string RECEIVER_ROOT = '/backups';

    /** Shared volume the receiver publishes the credentials into on start. */
    private const string KEY_DIR = '/ship-keys';

    /** Marker the receiver writes once the key pair and the known_hosts line are complete. */
    private const string READY_MARKER = self::KEY_DIR . '/ready';

    /** Private key of the pair the receiver authorized. */
    private const string SSH_KEY = self::KEY_DIR . '/id_ed25519';

    /** Host key of the receiver, the file `StrictHostKeyChecking=yes` is answered from. */
    private const string KNOWN_HOSTS = self::KEY_DIR . '/known_hosts';

    /** Fixture backup id; also the stem of the stored archive and sidecar names. */
    private const string BACKUP_ID = '2026-08-16_03-00-00';

    /** Environment the fixture backup was taken in; part of the stored name. */
    private const string BACKUP_ENV = 'test';

    /** How many times each byte value repeats in the fixture archive. */
    private const int ARCHIVE_PATTERN_REPEAT = 64;

    /** How many distinct byte values the fixture archive walks through. */
    private const int BYTE_VALUES = 256;

    /** Seconds a single transfer or remote command may take before the test gives up on it. */
    private const int COMMAND_TIMEOUT_SECONDS = 60;

    /** Seconds to wait for the receiver to publish its credentials and answer on its port. */
    private const int READY_TIMEOUT_SECONDS = 60;

    /** How often a wait re-checks, in microseconds. */
    private const int POLL_INTERVAL_MICROSECONDS = 100_000;

    private string $storeRoot = '';

    private ?EnvAccessor $previousEnv = null;

    /**
     * @throws CouldNotStartException When a probe of the receiver cannot be spawned
     * @throws FailedToClosePipeException When a probe's pipes cannot be closed
     * @throws FailedToGetStatusException When a probe's status cannot be read
     * @throws FailedToReadStdOutException When a probe's stdout cannot be read
     * @throws FailedToSetNonBlockingException When a probe's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When a probe's stderr cannot be read
     * @throws FailedToTerminateProcessException When a probe cannot be terminated
     * @throws FailedToWriteStdInException When a probe's stdin cannot be written
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storeRoot = sys_get_temp_dir() . '/hilos-ship-it-' . getmypid();
        $this->removeTree($this->storeRoot);
        mkdir($this->storeRoot . '/' . BackupScope::FULL->value, 0700, true);

        // A stub-backed accessor lets the test write the four deployment values the same way a
        // project's catalog would carry them (the BackupRestorerIntegrationTest precedent).
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('BACKUP_SHIP_SSH_KEY=' . self::SSH_KEY);
        putenv('BACKUP_SHIP_SSH_KNOWN_HOSTS=' . self::KNOWN_HOSTS);
        putenv(sprintf(
            'BACKUP_SHIP_TARGET=ssh://%s@%s%s',
            self::RECEIVER_USER,
            self::RECEIVER_HOST,
            self::RECEIVER_ROOT,
        ));

        $this->waitForReceiver();
        $this->clearReceiver();
    }

    /**
     * @throws CouldNotStartException When the cleanup command cannot be spawned
     * @throws FailedToClosePipeException When the cleanup command's pipes cannot be closed
     * @throws FailedToGetStatusException When the cleanup command's status cannot be read
     * @throws FailedToReadStdOutException When the cleanup command's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the cleanup command's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the cleanup command's stderr cannot be read
     * @throws FailedToTerminateProcessException When the cleanup command cannot be terminated
     * @throws FailedToWriteStdInException When the cleanup command's stdin cannot be written
     */
    protected function tearDown(): void
    {
        $this->clearReceiver();

        Hilos::$env = $this->previousEnv;
        putenv('BACKUP_SHIP_TARGET');
        putenv('BACKUP_SHIP_SSH_KEY');
        putenv('BACKUP_SHIP_SSH_KNOWN_HOSTS');
        $this->removeTree($this->storeRoot);

        parent::tearDown();
    }

    /**
     * The whole point of the leaf: a successful backup ends up on another machine, byte for byte,
     * archive first and sidecar second.
     *
     * @throws CouldNotStartException When a transfer cannot be spawned
     * @throws EnvInvalidValueException When a configured value cannot be read as a string
     * @throws EnvKeyInvalidException When an environment key is malformed
     * @throws EnvNotInCatalogException When the catalog declares no shipping destination
     * @throws EnvTypeMismatchException When the catalog declares a shipping value as another type
     * @throws FailedToClosePipeException When a transfer's pipes cannot be closed
     * @throws FailedToGetStatusException When a transfer's status cannot be read
     * @throws FailedToReadStdOutException When a transfer's stdout cannot be read
     * @throws FailedToSetNonBlockingException When a transfer's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When a transfer's stderr cannot be read
     * @throws FailedToTerminateProcessException When a transfer cannot be terminated
     * @throws FailedToWriteStdInException When a transfer's stdin cannot be written
     * @throws JsonException When the fixture sidecar cannot be encoded
     * @throws MissingEnvironmentVariableException When a shipping value is required and unset
     */
    public function testTheArchiveAndItsSidecarCrossToTheReceiver(): void
    {
        $archiveStep = $this->shipStoredBackup();
        $sidecarStep = new BackupShipPlanner()->sidecarStep($archiveStep);

        // Byte for byte, not merely present: an archive that survives the link with a mangled
        // line ending restores into nothing, and its digest would only say so after the disaster.
        self::assertSame(
            file_get_contents($archiveStep->localPath),
            $this->remoteContents(basename($archiveStep->localPath)),
            'the archive on the receiver differs from the one that was sent',
        );
        self::assertSame(
            file_get_contents($sidecarStep->localPath),
            $this->remoteContents(basename($sidecarStep->localPath)),
            'the sidecar on the receiver differs from the one that was sent',
        );
    }

    /**
     * The receiver is a mirror: what rotation removed here has to leave there, and it is a whole
     * scope directory being re-stated that takes it away, not a remembered list of deletions.
     *
     * @throws CouldNotStartException When a transfer cannot be spawned
     * @throws EnvInvalidValueException When a configured value cannot be read as a string
     * @throws EnvKeyInvalidException When an environment key is malformed
     * @throws EnvNotInCatalogException When the catalog declares no shipping destination
     * @throws EnvTypeMismatchException When the catalog declares a shipping value as another type
     * @throws FailedToClosePipeException When a transfer's pipes cannot be closed
     * @throws FailedToGetStatusException When a transfer's status cannot be read
     * @throws FailedToReadStdOutException When a transfer's stdout cannot be read
     * @throws FailedToSetNonBlockingException When a transfer's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When a transfer's stderr cannot be read
     * @throws FailedToTerminateProcessException When a transfer cannot be terminated
     * @throws FailedToWriteStdInException When a transfer's stdin cannot be written
     * @throws JsonException When the fixture sidecar cannot be encoded
     * @throws MissingEnvironmentVariableException When a shipping value is required and unset
     */
    public function testAMirrorPassTakesAwayWhatWasDeletedLocally(): void
    {
        $archiveStep = $this->shipStoredBackup();
        $sidecarStep = new BackupShipPlanner()->sidecarStep($archiveStep);

        unlink($archiveStep->localPath);
        unlink($sidecarStep->localPath);

        // With the archive gone the backup is no longer owed a copy, so the queue falls through
        // to the mirror the deletion marked dirty - the agent's own order of business.
        $mirror = new BackupShipPlanner()->plan(
            [$this->row()],
            $this->storeRoot,
            [],
            mirrorDirty: true,
            now: microtime(true),
        );
        self::assertNotNull($mirror, 'a local deletion left nothing for the mirror to do');
        self::assertSame(BackupShipStep::MIRROR, $mirror->step);

        $this->runToSuccess($this->shipper()->mirrorCommand($mirror->localPath, $mirror->scope));

        self::assertSame(
            [],
            $this->remoteNames(BackupScope::FULL->value),
            'the deleted pair is still on the receiver',
        );
    }

    /**
     * The file scheme, which is how a mounted network share is served without a driver of its own.
     *
     * It is here rather than beside the unit tests for one reason the ssh case cannot cover:
     * rsync creates the last missing component of a destination path and no more, so a
     * destination root that does not exist is a real failure mode of a hand-written value, and
     * only a real run says which of the two this is.
     *
     * @throws CouldNotStartException When a transfer cannot be spawned
     * @throws FailedToClosePipeException When a transfer's pipes cannot be closed
     * @throws FailedToGetStatusException When a transfer's status cannot be read
     * @throws FailedToReadStdOutException When a transfer's stdout cannot be read
     * @throws FailedToSetNonBlockingException When a transfer's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When a transfer's stderr cannot be read
     * @throws FailedToTerminateProcessException When a transfer cannot be terminated
     * @throws FailedToWriteStdInException When a transfer's stdin cannot be written
     * @throws JsonException When the fixture sidecar cannot be encoded
     */
    public function testTheFileSchemeCopiesIntoADirectoryOnThisMachine(): void
    {
        $receiver = $this->storeRoot . '-mirror';
        mkdir($receiver, 0700, true);
        $target = BackupShipTarget::parse('file://' . $receiver);
        self::assertNotNull($target);
        $shipper = BackupShipperFactory::fromTarget($target);
        self::assertNotNull($shipper);

        $archivePath = $this->storeFixtureBackup();
        $this->runToSuccess($shipper->pushCommand($archivePath, BackupScope::FULL->value));

        $landed = $receiver . '/' . BackupScope::FULL->value . '/' . basename($archivePath);
        self::assertFileExists($landed, 'rsync did not create the scope directory under the destination root');
        self::assertSame(file_get_contents($archivePath), file_get_contents($landed));

        unlink($archivePath);
        $this->runToSuccess($shipper->mirrorCommand(
            $this->storeRoot . '/' . BackupScope::FULL->value,
            BackupScope::FULL->value,
        ));
        self::assertFileDoesNotExist($landed, 'the mirror left a copy of a locally deleted archive');

        $this->removeTree($receiver);
    }

    /**
     * Stores a fixture backup and ships its archive the way the agent would: ask the planner what
     * is due, run what the driver builds for it.
     *
     * @return BackupShipPlan The archive step that was carried out, the sidecar step derives from it
     * @throws CouldNotStartException When a transfer cannot be spawned
     * @throws EnvInvalidValueException When a configured value cannot be read as a string
     * @throws EnvKeyInvalidException When an environment key is malformed
     * @throws EnvNotInCatalogException When the catalog declares no shipping destination
     * @throws EnvTypeMismatchException When the catalog declares a shipping value as another type
     * @throws FailedToClosePipeException When a transfer's pipes cannot be closed
     * @throws FailedToGetStatusException When a transfer's status cannot be read
     * @throws FailedToReadStdOutException When a transfer's stdout cannot be read
     * @throws FailedToSetNonBlockingException When a transfer's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When a transfer's stderr cannot be read
     * @throws FailedToTerminateProcessException When a transfer cannot be terminated
     * @throws FailedToWriteStdInException When a transfer's stdin cannot be written
     * @throws JsonException When the fixture sidecar cannot be encoded
     * @throws MissingEnvironmentVariableException When a shipping value is required and unset
     */
    private function shipStoredBackup(): BackupShipPlan
    {
        $this->storeFixtureBackup();
        $shipper = $this->shipper();

        $planner = new BackupShipPlanner();
        $archiveStep = $planner->plan([$this->row()], $this->storeRoot, [], false, microtime(true));
        self::assertNotNull($archiveStep, 'the stored backup was not offered for shipping');
        self::assertSame(BackupShipStep::PUSH_ARCHIVE, $archiveStep->step);

        // The order is the local publish order, and it is a sequence rather than two candidates:
        // the sidecar step is derived from the finished archive step.
        $this->runToSuccess($shipper->pushCommand($archiveStep->localPath, $archiveStep->scope));
        $sidecarStep = $planner->sidecarStep($archiveStep);
        $this->runToSuccess($shipper->pushCommand($sidecarStep->localPath, $sidecarStep->scope));

        return $archiveStep;
    }

    /**
     * The driver the configured destination is served by.
     *
     * @return BackupShipperInterface Driver built from the four deployment values
     * @throws EnvInvalidValueException When a configured value cannot be read as a string
     * @throws EnvKeyInvalidException When an environment key is malformed
     * @throws EnvNotInCatalogException When the catalog declares no shipping destination
     * @throws EnvTypeMismatchException When the catalog declares a shipping value as another type
     * @throws MissingEnvironmentVariableException When a shipping value is required and unset
     */
    private function shipper(): BackupShipperInterface
    {
        $target = BackupShipTarget::fromEnv();
        self::assertNotNull($target, 'the configured destination did not parse');

        $shipper = BackupShipperFactory::fromTarget($target);
        self::assertNotNull($shipper, 'no driver serves the configured destination');

        return $shipper;
    }

    /**
     * Writes the fixture archive and its sidecar where {@see BackupCreator} publishes them.
     *
     * The archive carries every byte value rather than a line of text: a transfer that mangles
     * one is the failure this test exists to catch, and text survives most of the ways that go
     * wrong.
     *
     * @return string Absolute path of the stored archive
     * @throws JsonException When the fixture sidecar cannot be encoded
     */
    private function storeFixtureBackup(): string
    {
        $base = $this->storeRoot . '/' . BackupScope::FULL->value . '/'
            . BackupCreator::archiveBaseName(self::BACKUP_ID, self::BACKUP_ENV, BackupScope::FULL);

        $payload = '';
        for ($byte = 0; $byte < self::BYTE_VALUES; $byte++) {
            $payload .= str_repeat(chr($byte), self::ARCHIVE_PATTERN_REPEAT);
        }

        $archivePath = $base . BackupHistoryScanner::ARCHIVE_EXTENSION;
        file_put_contents($archivePath, $payload);
        file_put_contents(
            $base . BackupHistoryScanner::SIDECAR_EXTENSION,
            json_encode($this->metadata()->toArray(), JSON_THROW_ON_ERROR),
        );

        return $archivePath;
    }

    /**
     * @return BackupHistory Index row of the fixture backup, as the agent would hold it
     */
    private function row(): BackupHistory
    {
        return new BackupHistory(StateBackupHistory::fromMetadata($this->metadata()));
    }

    /**
     * @return BackupMetadata Sidecar of the fixture backup: successful, never shipped
     */
    private function metadata(): BackupMetadata
    {
        return new BackupMetadata(
            id: self::BACKUP_ID,
            createdAt: '2026-08-16T03:00:00+00:00',
            env: self::BACKUP_ENV,
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: self::ARCHIVE_PATTERN_REPEAT * self::BYTE_VALUES,
            durationSeconds: 1,
            keep: false,
            status: BackupStatus::SUCCESS,
        );
    }

    /**
     * Waits until the receiver has published its credentials and answers on its port.
     *
     * @throws CouldNotStartException When the probe cannot be spawned
     * @throws FailedToClosePipeException When the probe's pipes cannot be closed
     * @throws FailedToGetStatusException When the probe's status cannot be read
     * @throws FailedToReadStdOutException When the probe's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the probe's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the probe's stderr cannot be read
     * @throws FailedToTerminateProcessException When the probe cannot be terminated
     * @throws FailedToWriteStdInException When the probe's stdin cannot be written
     */
    private function waitForReceiver(): void
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;
        $reason = 'the receiver never published ' . self::READY_MARKER;

        while (microtime(true) < $deadline) {
            clearstatcache();
            if (is_file(self::READY_MARKER) && is_file(self::SSH_KEY) && is_file(self::KNOWN_HOSTS)) {
                $probe = $this->execute('ssh', [...$this->sshOptions(), $this->account(), 'true']);
                if ($probe['code'] === 0) {
                    return;
                }
                $reason = 'the receiver refused a pinned key login: ' . trim($probe['stderr']);
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        self::fail(sprintf(
            '%s within %ds - is the stand up? (composer run test:framework:up)',
            $reason,
            self::READY_TIMEOUT_SECONDS,
        ));
    }

    /**
     * Empties the destination root on the receiver, so one case never reads another's leftovers.
     *
     * @throws CouldNotStartException When the cleanup command cannot be spawned
     * @throws FailedToClosePipeException When the cleanup command's pipes cannot be closed
     * @throws FailedToGetStatusException When the cleanup command's status cannot be read
     * @throws FailedToReadStdOutException When the cleanup command's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the cleanup command's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the cleanup command's stderr cannot be read
     * @throws FailedToTerminateProcessException When the cleanup command cannot be terminated
     * @throws FailedToWriteStdInException When the cleanup command's stdin cannot be written
     */
    private function clearReceiver(): void
    {
        $this->remoteShell('find ' . self::RECEIVER_ROOT . ' -mindepth 1 -delete');
    }

    /**
     * Reads back a file the transfer left in the fixture's scope directory.
     *
     * @param string $name Stored file name, archive or sidecar
     * @return string Contents as the receiver holds them
     * @throws CouldNotStartException When the read command cannot be spawned
     * @throws FailedToClosePipeException When the read command's pipes cannot be closed
     * @throws FailedToGetStatusException When the read command's status cannot be read
     * @throws FailedToReadStdOutException When the read command's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the read command's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the read command's stderr cannot be read
     * @throws FailedToTerminateProcessException When the read command cannot be terminated
     * @throws FailedToWriteStdInException When the read command's stdin cannot be written
     */
    private function remoteContents(string $name): string
    {
        return $this->remoteShell(sprintf(
            'cat %s/%s/%s',
            self::RECEIVER_ROOT,
            BackupScope::FULL->value,
            $name,
        ));
    }

    /**
     * Lists what the receiver holds for one scope.
     *
     * A missing directory reads as an empty one on purpose: a mirror pass that removed the last
     * file is allowed to have removed the directory with it, and both answers mean the same
     * thing to the operator.
     *
     * @param string $scope Scope value naming the directory
     * @return list<string> Stored file names, sorted
     * @throws CouldNotStartException When the listing command cannot be spawned
     * @throws FailedToClosePipeException When the listing command's pipes cannot be closed
     * @throws FailedToGetStatusException When the listing command's status cannot be read
     * @throws FailedToReadStdOutException When the listing command's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the listing command's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the listing command's stderr cannot be read
     * @throws FailedToTerminateProcessException When the listing command cannot be terminated
     * @throws FailedToWriteStdInException When the listing command's stdin cannot be written
     */
    private function remoteNames(string $scope): array
    {
        $listing = $this->remoteShell(sprintf(
            'ls -1 %s/%s 2>/dev/null || true',
            self::RECEIVER_ROOT,
            $scope,
        ));

        $names = array_values(array_filter(explode("\n", trim($listing)), static fn(string $line): bool => $line !== ''));
        sort($names);

        return $names;
    }

    /**
     * Runs one command on the receiver and returns its output, failing the test if it does not.
     *
     * @param string $command Shell command for the receiver's own shell
     * @return string Standard output of the command
     * @throws CouldNotStartException When the command cannot be spawned
     * @throws FailedToClosePipeException When the command's pipes cannot be closed
     * @throws FailedToGetStatusException When the command's status cannot be read
     * @throws FailedToReadStdOutException When the command's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the command's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the command's stderr cannot be read
     * @throws FailedToTerminateProcessException When the command cannot be terminated
     * @throws FailedToWriteStdInException When the command's stdin cannot be written
     */
    private function remoteShell(string $command): string
    {
        $result = $this->execute('ssh', [...$this->sshOptions(), $this->account(), $command]);
        self::assertSame(0, $result['code'], sprintf('`%s` failed on the receiver: %s', $command, $result['stderr']));

        return $result['stdout'];
    }

    /**
     * Spawns a transfer the driver built and asserts it finished cleanly.
     *
     * Success is the driver's exit code and nothing else, exactly as the agent judges it.
     *
     * @param BackupShipCommand $command Transfer to run
     * @throws CouldNotStartException When the transfer cannot be spawned
     * @throws FailedToClosePipeException When the transfer's pipes cannot be closed
     * @throws FailedToGetStatusException When the transfer's status cannot be read
     * @throws FailedToReadStdOutException When the transfer's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the transfer's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the transfer's stderr cannot be read
     * @throws FailedToTerminateProcessException When the transfer cannot be terminated
     * @throws FailedToWriteStdInException When the transfer's stdin cannot be written
     */
    private function runToSuccess(BackupShipCommand $command): void
    {
        $result = $this->execute($command->binary, $command->args);
        self::assertSame(0, $result['code'], sprintf(
            '`%s %s` exited %d: %s',
            $command->binary,
            implode(' ', $command->args),
            $result['code'],
            $result['stderr'],
        ));
    }

    /**
     * Runs a command to completion, the way the agent polls its transfer child.
     *
     * @param string $binary Executable to spawn
     * @param list<string> $args Arguments passed verbatim as argv entries
     * @return array{code: int, stdout: string, stderr: string} Exit code and the output either stream carried
     * @throws CouldNotStartException When the process cannot be spawned
     * @throws FailedToClosePipeException When the process's pipes cannot be closed
     * @throws FailedToGetStatusException When the process's status cannot be read
     * @throws FailedToReadStdOutException When the process's stdout cannot be read
     * @throws FailedToSetNonBlockingException When the process's pipes cannot be made non-blocking
     * @throws FailedToSetStdErrException When the process's stderr cannot be read
     * @throws FailedToTerminateProcessException When the process cannot be terminated
     * @throws FailedToWriteStdInException When the process's stdin cannot be written
     */
    private function execute(string $binary, array $args): array
    {
        $process = new Process($binary, $args);
        $stdout = '';
        $stderr = '';

        $deadline = microtime(true) + self::COMMAND_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $process->tick();
            $stdout .= $process->getStdOut();
            $stderr .= $process->getStdErr();
            if (!$process->getStatus()[Process::STATUS_RUNNING]) {
                break;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        // Idempotent, and the only place a still-running command is killed: the loop above left
        // it running exactly when the deadline passed, which the null exit code then reports.
        $process->halt();
        $stdout .= $process->getStdOut();
        $stderr .= $process->getStdErr();

        $code = $process->getExitCode();
        if ($code === null) {
            self::fail(sprintf('`%s` did not finish within %ds', $binary, self::COMMAND_TIMEOUT_SECONDS));
        }

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Ssh options every command of this test connects with.
     *
     * The same pinning the driver builds, plus `BatchMode`: a prompt here would hang the run
     * until the deadline instead of failing with what went wrong.
     *
     * @return list<string> Argv entries preceding the account
     */
    private function sshOptions(): array
    {
        return [
            '-i',
            self::SSH_KEY,
            '-o',
            'UserKnownHostsFile=' . self::KNOWN_HOSTS,
            '-o',
            'StrictHostKeyChecking=yes',
            '-o',
            'BatchMode=yes',
        ];
    }

    /**
     * @return string Login and host the receiver is reached at
     */
    private function account(): string
    {
        return self::RECEIVER_USER . '@' . self::RECEIVER_HOST;
    }

    /**
     * Removes a directory tree, ignoring one that is not there.
     *
     * @param string $path Absolute path of the tree to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : unlink($entry);
        }

        rmdir($path);
    }
}
