<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\BackupException;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Hilos;

/**
 * BackupTestAgeCommand - age a stored backup's sidecar createdAt into the past (test-only).
 *
 * The retention buckets a backup by its sidecar createdAt (files=truth), which the create
 * path stamps at capture time. To assert rotation without waiting out real days or mocking
 * the clock, a test needs an existing backup to *look* older. This fixture primitive rewrites
 * only the createdAt of a stored sidecar in place, atomically (temp file in the same directory,
 * then rename), leaving every other field untouched; a following `test:backup:prune` then sees
 * the aged backup as a rotation candidate. It never touches runtime state - the agent's own
 * rescan re-mirrors the index from the rewritten sidecar (files=truth).
 *
 * The backup id resolves to at most one sidecar; when the same id exists under more than one
 * scope subdirectory, `--scope` disambiguates. Test-only ({@see TestOnlyCommand}).
 */
class BackupTestAgeCommand extends TestOnlyCommand
{
    /** Temp-file prefix for the atomic in-place sidecar rewrite. */
    private const string TEMP_PREFIX = '.tmp-age-';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:backup:age)
     */
    public function getName(): string
    {
        return CliCommands::BACKUP_TEST_AGE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return "Age a stored backup's sidecar createdAt into the past (test-only)";
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:backup:age <id> (--at=<ISO-8601> | --days=<N>) [--scope=<scope>]

Description:
  Rewrite a stored backup's sidecar createdAt so retention treats it as older, driving
  rotation without waiting out real time. Rewrites only createdAt, atomically; runtime
  state is untouched (the agent re-mirrors the index from the sidecar). Refuses on a
  production-like environment.

Arguments:
  <id>              Backup id (the YYYY-MM-DD_HH-mm-ss stem)

Options:
  --at=<ISO-8601>   Explicit createdAt to write (mutually exclusive with --days)
  --days=<N>        Write a createdAt N days before now (mutually exclusive with --at)
  --scope=<scope>   Disambiguate when the id exists under more than one scope
                    (full | schema-seed | schema-only)

Usage:
  php cli.php test:backup:age 2026-07-19_10-30-00 --days=40
  php cli.php test:backup:age 2026-07-19_10-30-00 --at=2026-01-01T00:00:00+00:00 --scope=full
HELP;
    }

    /**
     * Resolves the aged createdAt from the options and rewrites the target sidecar.
     *
     * @param array<string, mixed> $options Parsed options (--at | --days, optional --scope)
     * @param list<string> $args Positional args: [0] backup id
     * @return int Exit code (0 on success)
     */
    protected function run(array $options, array $args): int
    {
        $id = $args[0] ?? '';
        if ($id === '') {
            echo "Usage: test:backup:age <id> (--at=<ISO-8601> | --days=<N>) [--scope=<scope>]\n";
            return ExitCode::INVALID_ARGUMENT;
        }

        $createdAt = $this->resolveCreatedAt($options);
        if ($createdAt === null) {
            echo "Specify exactly one of --at=<ISO-8601> or --days=<N>\n";
            return ExitCode::INVALID_ARGUMENT;
        }

        $scope = null;
        $scopeOption = $options[BackupConstants::SCOPE_OPTION] ?? null;
        if ($scopeOption !== null) {
            $scope = BackupScope::fromString(is_string($scopeOption) ? $scopeOption : '');
            if ($scope === null) {
                echo "Unknown scope: {$scopeOption}\n";
                return ExitCode::INVALID_ARGUMENT;
            }
        }

        $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        if ($root === '') {
            echo "Backup directory (BACKUP_DIR) is not configured\n";
            return ExitCode::CONFIG_ERROR;
        }

        try {
            $sidecarPath = $this->retimeSidecar($root, $id, $scope, $createdAt);
        } catch (BackupException $e) {
            echo $e->getMessage() . "\n";
            return ExitCode::ERROR;
        }

        echo sprintf("Aged backup %s createdAt=%s (%s)\n", $id, $createdAt->format(DateTimeInterface::ATOM), $sidecarPath);
        return ExitCode::SUCCESS;
    }

    /**
     * Rewrites the createdAt of the single sidecar matching the id, atomically, in place.
     *
     * Globs each candidate scope subdirectory for `<id>-*.json`; exactly one match is
     * required. The rewrite preserves every other sidecar field and is published over the
     * same temp+fsync+rename idiom the create path uses, so a reader never sees a partial write.
     *
     * @param string $root Backup storage root
     * @param string $id Backup id whose sidecar is retimed
     * @param ?BackupScope $scope Scope to search, or null to search every scope
     * @param DateTimeImmutable $createdAt New creation instant to stamp
     * @return string Absolute path of the rewritten sidecar
     * @throws BackupException When no sidecar matches, more than one matches, or the rewrite fails
     */
    public function retimeSidecar(string $root, string $id, ?BackupScope $scope, DateTimeImmutable $createdAt): string
    {
        $scopes = $scope !== null ? [$scope] : BackupScope::cases();

        $matches = [];
        foreach ($scopes as $candidateScope) {
            $pattern = $root . '/' . $candidateScope->value . '/' . $id . '-*' . BackupHistoryScanner::SIDECAR_EXTENSION;
            foreach (glob($pattern) ?: [] as $path) {
                $matches[] = $path;
            }
        }

        if ($matches === []) {
            $where = $scope !== null ? " in scope '{$scope->value}'" : '';
            throw new BackupException("No backup sidecar found for id '{$id}'{$where}");
        }
        if (count($matches) > 1) {
            throw new BackupException("Ambiguous backup id '{$id}' matches multiple sidecars; pass --scope to disambiguate");
        }

        $sidecarPath = $matches[0];
        $metadata = $this->readSidecar($sidecarPath);

        $rewritten = new BackupMetadata(
            $metadata->id,
            $createdAt->format(DateTimeInterface::ATOM),
            $metadata->env,
            $metadata->scope,
            $metadata->connections,
            $metadata->sizeBytes,
            $metadata->durationSeconds,
            $metadata->keep,
            $metadata->status,
            $metadata->warnings,
        );

        $this->publishAtomically($sidecarPath, $rewritten->toArray());

        return $sidecarPath;
    }

    /**
     * Resolves the new createdAt from exactly one of --at / --days, or null when invalid.
     *
     * @param array<string, mixed> $options Parsed options
     * @return ?DateTimeImmutable New instant, or null when neither/both/malformed
     */
    private function resolveCreatedAt(array $options): ?DateTimeImmutable
    {
        $atGiven = array_key_exists(BackupConstants::AT_OPTION, $options);
        $daysGiven = array_key_exists(BackupConstants::DAYS_OPTION, $options);
        if ($atGiven === $daysGiven) {
            return null;
        }

        if ($atGiven) {
            $at = $options[BackupConstants::AT_OPTION];
            if (!is_string($at)) {
                return null;
            }
            try {
                return new DateTimeImmutable($at);
            } catch (Exception) {
                return null;
            }
        }

        $days = $options[BackupConstants::DAYS_OPTION];
        if (!is_string($days) || !ctype_digit($days)) {
            return null;
        }

        return new DateTimeImmutable()->modify('-' . (int)$days . ' days');
    }

    /**
     * Reads and decodes one backup sidecar into its metadata.
     *
     * @param string $sidecarPath Absolute sidecar path
     * @return BackupMetadata Decoded sidecar metadata
     * @throws BackupException When the sidecar is missing or not valid JSON
     */
    private function readSidecar(string $sidecarPath): BackupMetadata
    {
        $raw = @file_get_contents($sidecarPath);
        if ($raw === false) {
            throw new BackupException("Backup sidecar not found: {$sidecarPath}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BackupException("Backup sidecar is not valid JSON: {$sidecarPath}");
        }

        return BackupMetadata::fromArray($decoded);
    }

    /**
     * Writes JSON to a same-directory temp file, fsyncs it, then renames it over the target.
     *
     * @param string $path Final sidecar path
     * @param array<string, mixed> $data Sidecar payload
     * @throws BackupException When encoding, writing, or renaming fails
     */
    private function publishAtomically(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new BackupException("Failed to encode JSON for {$path}");
        }

        $tmpPath = dirname($path) . '/' . self::TEMP_PREFIX . basename($path) . '-' . getmypid();
        if (@file_put_contents($tmpPath, $json) === false) {
            throw new BackupException("Failed to write {$tmpPath}");
        }

        $handle = @fopen($tmpPath, 'r');
        if ($handle !== false) {
            @fflush($handle);
            @fsync($handle);
            @fclose($handle);
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new BackupException("Failed to publish {$path}");
        }
    }
}
