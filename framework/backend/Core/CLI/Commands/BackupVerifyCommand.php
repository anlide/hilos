<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScanAnomaly;
use Hilos\Backup\BackupScanAnomalyType;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Backup\BackupVerifyResult;
use Hilos\Backup\Exception\BackupDumpFailedException;
use Hilos\Backup\Exception\BackupException;
use Hilos\Backup\Exception\BackupMetadataIncompleteException;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * BackupVerifyCommand - check stored archives against the digests recorded when they were written.
 *
 * An operator command, not a test fixture: it runs on production, answers "is this archive still
 * the file we wrote?", and is the only way to ask that question before HIL-274 puts a gate in
 * front of a restore. Hashing happens in THIS process, never in the monopoly backup agent - a
 * multi-gigabyte hash on the daemon loop would block backup creation and page actions for
 * minutes.
 *
 * Storage is the truth (files=truth): the sweep reads sidecars off disk, and the verification is
 * stamped back into the sidecar it came from. Nothing is announced to the daemon afterwards - it
 * watches storage itself (HIL-528) and picks the rewritten sidecars up, whether or not this
 * command could have reached it.
 */
class BackupVerifyCommand implements CommandInterface
{
    /** Digest characters shown when a mismatch prints both sides; the full 64 would drown the line. */
    private const int DIGEST_PREVIEW = 12;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (backup:verify)
     */
    public function getName(): string
    {
        return CliCommands::BACKUP_VERIFY;
    }

    /**
     * Declares the departure: this reading happens in the CLI process, for the reason it names.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliRead(
            'hashing gigabytes must not run inside the monopolistic backup agent\'s loop (BackupVerifier)',
        );
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Verify stored backup archives against their recorded checksums';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: backup:verify

Description:
  Check stored backup archives against the sha256 digest recorded in their sidecar when
  they were written. Without an id every stored backup is checked; --scope narrows the
  sweep. An archive whose size already disagrees is reported without being hashed. A
  backup written before checksums existed carries no digest and is skipped, which is not
  an error. Each ok/mismatch is stamped back into the sidecar, and the running daemon
  notices the rewrite on its own, so an open admin list learns of the result without
  waiting for a restart - it arrives like any other row change, behind the list's Apply
  gate.

Summary line:
  checked N: ok N, mismatch N, skipped N, unverified N
  skipped    = no digest recorded, so there was nothing to check (not an error)
  unverified = the archive is missing or unreadable, its sidecar could not be read or
               paired, or the verdict could not be written back

Usage:
  php cli.php backup:verify [id] [--scope=<scope>]

Options:
  --scope=<scope>    Only check backups of this scope (full | schema-seed | schema-only)

Exit codes:
  0  everything checked matched, or had nothing to check
  1  a mismatch, or anything left unverified
  2  unknown backup id or scope
  3  BACKUP_DIR is not configured

Examples:
  php cli.php backup:verify
  php cli.php backup:verify --scope=full
  php cli.php backup:verify 2026-08-02_03-00-00
HELP;
    }

    /**
     * Verifies the requested archives, stamps the outcomes, and reports a summary.
     *
     * @param array<string, mixed> $options Parsed options (scope)
     * @param list<string> $args Positional args (an optional backup id)
     * @return int Exit code (0 clean, 1 mismatch or anything unverified, 2 bad argument,
     *     3 backup storage not configured)
     */
    public function execute(array $options, array $args): int
    {
        try {
            $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }
        if ($root === '') {
            echo "Error: backup directory (BACKUP_DIR) is not configured\n";

            return ExitCode::CONFIG_ERROR;
        }

        $scope = null;
        if (isset($options[BackupConstants::SCOPE_OPTION])) {
            $scope = BackupScope::fromString((string)$options[BackupConstants::SCOPE_OPTION]);
            if ($scope === null) {
                echo "Error: unknown backup scope: {$options[BackupConstants::SCOPE_OPTION]}\n";

                return ExitCode::INVALID_ARGUMENT;
            }
        }

        $id = $args[0] ?? null;
        $scan = new BackupHistoryScanner()->scan($root);
        $targets = $this->targets($scan->metadatas, $id, $scope);
        // A backup whose archive is gone never reaches the indexable set: the scanner files it
        // as an anomaly instead. That is precisely the failure this command exists to catch, so
        // the anomalies of the swept scopes are reported here and not left to the daemon's log.
        $anomalies = $this->relevantAnomalies($scan->anomalies, $id, $scope);

        if ($targets === [] && $anomalies === []) {
            echo $id === null ? "No stored backups to check\n" : "Error: unknown backup id: {$id}\n";

            return $id === null ? ExitCode::SUCCESS : ExitCode::INVALID_ARGUMENT;
        }

        return $this->verifyAll($root, $targets, $anomalies);
    }

    /**
     * Verifies every target, stamps the outcomes, and prints the per-archive lines and summary.
     *
     * @param string $root Backup storage root
     * @param list<BackupMetadata> $targets Sidecars to check
     * @param list<BackupScanAnomaly> $anomalies Storage anomalies covering the same sweep
     * @return int Exit code for the sweep
     */
    private function verifyAll(string $root, array $targets, array $anomalies): int
    {
        $verifier = new BackupVerifier();
        $counts = [
            BackupVerifyOutcome::OK->value => 0,
            BackupVerifyOutcome::MISMATCH->value => 0,
            BackupVerifyOutcome::NO_DIGEST->value => 0,
        ];
        // Everything the sweep could not conclude about: a missing or unreadable archive, a
        // sidecar that could not be paired or parsed, a verdict that could not be persisted.
        // Deliberately NOT folded into "skipped" - that word means "carries no digest, nothing
        // to check", and using it for a vanished archive would make the report contradict the
        // exit code it prints next to.
        $unverified = 0;

        foreach ($targets as $metadata) {
            $result = $verifier->verify($this->archivePath($root, $metadata), $metadata);
            $this->printResult($metadata, $result);

            if (isset($counts[$result->outcome->value])) {
                $counts[$result->outcome->value]++;
            }
            if (
                $result->outcome === BackupVerifyOutcome::ARCHIVE_MISSING
                || $result->outcome === BackupVerifyOutcome::UNREADABLE
            ) {
                $unverified++;
            }

            if ($result->outcome !== BackupVerifyOutcome::OK && $result->outcome !== BackupVerifyOutcome::MISMATCH) {
                continue;
            }

            try {
                $this->stamp($root, $metadata, $result->outcome);
            } catch (BackupException $e) {
                // The check itself still holds, so the sweep goes on - but a store whose
                // sidecars cannot be rewritten must not report a clean run.
                echo "  warning: could not record the result: {$e->getMessage()}\n";
                $unverified++;
            }
        }

        foreach ($anomalies as $anomaly) {
            $this->printAnomaly($anomaly);
            $unverified++;
        }

        printf(
            "checked %d: ok %d, mismatch %d, skipped %d, unverified %d\n",
            count($targets) + count($anomalies),
            $counts[BackupVerifyOutcome::OK->value],
            $counts[BackupVerifyOutcome::MISMATCH->value],
            $counts[BackupVerifyOutcome::NO_DIGEST->value],
            $unverified,
        );

        return $counts[BackupVerifyOutcome::MISMATCH->value] > 0 || $unverified > 0
            ? ExitCode::ERROR
            : ExitCode::SUCCESS;
    }

    /**
     * Collects the sidecars a run should check.
     *
     * @param list<BackupMetadata> $metadatas Every indexable sidecar the scan found
     * @param ?string $id Single backup id, or null to sweep everything
     * @param ?BackupScope $scope Scope filter, or null for every scope
     * @return list<BackupMetadata> Sidecars to check
     */
    private function targets(array $metadatas, ?string $id, ?BackupScope $scope): array
    {
        $targets = [];
        foreach ($metadatas as $metadata) {
            if ($id !== null && $metadata->id !== $id) {
                continue;
            }
            if ($scope !== null && $metadata->scope !== $scope) {
                continue;
            }
            $targets[] = $metadata;
        }

        return $targets;
    }

    /**
     * Narrows the scan's anomalies to what this run swept.
     *
     * Only the two anomalies about a record we were asked to verify are taken: a sidecar whose
     * archive is gone, and a sidecar that cannot be read. An orphaned archive with no sidecar is
     * a warn-level pairing gap that rotation cleans up - there is nothing to verify it against,
     * and failing a verification run over it would cry wolf.
     *
     * A phantom sidecar is still readable, so it is asked who it is rather than having its
     * identity guessed from its file name: matching `<id>-` as a prefix attributed one backup's
     * missing archive to another whose id it merely starts with (`nightly` vs `nightly-2`), which
     * failed a healthy run and swallowed the "unknown backup id" answer.
     *
     * An anomaly with no readable identity - a broken sidecar, or a phantom one that stopped
     * reading between the scan and here - belongs to a sweep and never to a by-id run, which
     * could not honestly claim it. It is still reported there, because dropping an anomaly
     * nobody could identify is how a sweep goes green over an archive it never checked.
     *
     * @param list<BackupScanAnomaly> $anomalies Anomalies the scan reported
     * @param ?string $id Single backup id, or null to sweep everything
     * @param ?BackupScope $scope Scope filter, or null for every scope
     * @return list<BackupScanAnomaly> Anomalies belonging to this sweep
     */
    private function relevantAnomalies(array $anomalies, ?string $id, ?BackupScope $scope): array
    {
        $relevant = [];
        foreach ($anomalies as $anomaly) {
            if ($anomaly->type === BackupScanAnomalyType::TAR_WITHOUT_SIDECAR) {
                continue;
            }

            $metadata = $anomaly->type === BackupScanAnomalyType::BROKEN_SIDECAR
                ? null
                : $this->readSidecar($anomaly->path);

            if ($metadata === null) {
                // Nameless: the scope is still known from the directory the record lives in,
                // but the id is not, so the anomaly is reported to a sweep and withheld from a
                // by-id run rather than dropped.
                if ($id === null && ($scope === null || basename(dirname($anomaly->path)) === $scope->value)) {
                    $relevant[] = $anomaly;
                }
                continue;
            }

            if ($id !== null && $metadata->id !== $id) {
                continue;
            }
            if ($scope !== null && $metadata->scope !== $scope) {
                continue;
            }
            $relevant[] = $anomaly;
        }

        return $relevant;
    }

    /**
     * Reads one sidecar off disk, or null when it cannot be read, parsed, or names no backup.
     *
     * @param string $sidecarPath Absolute sidecar path
     * @return ?BackupMetadata Decoded metadata, or null when the sidecar is unusable
     */
    private function readSidecar(string $sidecarPath): ?BackupMetadata
    {
        if (!is_file($sidecarPath) || !is_readable($sidecarPath)) {
            return null;
        }

        $raw = file_get_contents($sidecarPath);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        try {
            return BackupMetadata::fromArray($decoded);
        } catch (BackupMetadataIncompleteException) {
            return null;
        }
    }

    /**
     * Prints one storage anomaly in the same shape as a verified archive.
     *
     * @param BackupScanAnomaly $anomaly Anomaly the scan reported
     */
    private function printAnomaly(BackupScanAnomaly $anomaly): void
    {
        $outcome = $anomaly->type === BackupScanAnomalyType::SIDECAR_WITHOUT_TAR
            ? BackupVerifyOutcome::ARCHIVE_MISSING
            : BackupVerifyOutcome::UNREADABLE;

        printf("%-24s %-12s %12s  %s\n", basename($anomaly->path), '', '-', $outcome->value);
    }

    /**
     * Records a verdict about an archive's content in the sidecar it came from.
     *
     * @param string $root Backup storage root
     * @param BackupMetadata $metadata Sidecar that was checked
     * @param BackupVerifyOutcome $outcome What the check concluded (ok or mismatch only)
     * @throws BackupException When the sidecar is gone or its scope/root is invalid
     * @throws BackupDumpFailedException When the rewritten sidecar cannot be published
     */
    private function stamp(string $root, BackupMetadata $metadata, BackupVerifyOutcome $outcome): void
    {
        $verifiedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);

        new BackupCreator()->recordVerification($metadata, $root, $outcome, $verifiedAt);
    }

    /**
     * Prints one archive's verdict, with both digests when they disagree.
     *
     * @param BackupMetadata $metadata Sidecar that was checked
     * @param BackupVerifyResult $result What the check concluded
     */
    private function printResult(BackupMetadata $metadata, BackupVerifyResult $result): void
    {
        printf(
            "%-24s %-12s %12d  %s\n",
            $metadata->id,
            $metadata->scope->value,
            $metadata->sizeBytes,
            $result->outcome->value,
        );

        if ($result->outcome !== BackupVerifyOutcome::MISMATCH) {
            return;
        }

        if ($result->reason !== null) {
            echo "  {$result->reason}\n";

            return;
        }

        printf(
            "  expected %s… actual %s…\n",
            substr((string)$result->expected, 0, self::DIGEST_PREVIEW),
            substr((string)$result->actual, 0, self::DIGEST_PREVIEW),
        );
    }

    /**
     * Builds the stored archive path of one sidecar.
     *
     * @param string $root Backup storage root
     * @param BackupMetadata $metadata Sidecar describing the archive
     * @return string Absolute archive path
     */
    private function archivePath(string $root, BackupMetadata $metadata): string
    {
        return $root . '/' . $metadata->scope->value . '/'
            . BackupCreator::archiveBaseName($metadata->id, $metadata->env, $metadata->scope)
            . BackupHistoryScanner::ARCHIVE_EXTENSION;
    }
}
