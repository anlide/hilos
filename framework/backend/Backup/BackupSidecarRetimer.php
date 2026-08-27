<?php

declare(strict_types=1);

namespace Hilos\Backup;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Exception\BackupException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * BackupSidecarRetimer - rewrites a stored backup's sidecar createdAt into the past (test-only).
 *
 * Retention buckets a backup by its sidecar createdAt (files=truth), which the create path stamps
 * at capture time. To assert rotation without waiting out real days or mocking the clock, a test
 * needs an existing backup to *look* older. This rewrites only that one field, in place and
 * atomically, leaving every other field untouched; a following prune then sees a rotation
 * candidate.
 *
 * It is a class of its own rather than a method on the CLI command it was born in, because the
 * work belongs to whoever owns the backup catalog and that is {@see BackupAgent} - the CLI half
 * now only asks over the command channel. Runtime state is untouched here on purpose: the agent
 * re-mirrors its index from the rewritten sidecar (files=truth).
 */
final class BackupSidecarRetimer
{
    /** Temp-file prefix for the atomic in-place sidecar rewrite. */
    private const string TEMP_PREFIX = '.tmp-age-';

    /**
     * Rewrites the createdAt of the single sidecar matching the id, atomically, in place.
     *
     * Globs each candidate scope subdirectory for `<id>-*.json`; exactly one match is required.
     * The rewrite preserves every other sidecar field and is published over the same
     * temp+fsync+rename idiom the create path uses, so a reader never sees a partial write.
     *
     * @param string $root Backup storage root
     * @param string $id Backup id whose sidecar is retimed
     * @param ?BackupScope $scope Scope to search, or null to search every scope
     * @param DateTimeImmutable $createdAt New creation instant to stamp
     * @return string Absolute path of the rewritten sidecar
     * @throws BackupException When no sidecar matches, more than one matches, or the rewrite fails
     */
    public function retime(string $root, string $id, ?BackupScope $scope, DateTimeImmutable $createdAt): string
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
            // external-boundary: the neutral element of the message below — an unscoped search says so
            $where = $scope !== null ? " in scope '{$scope->value}'" : '';
            throw new BackupException("No backup sidecar found for id '{$id}'{$where}");
        }
        if (count($matches) > 1) {
            throw new BackupException("Ambiguous backup id '{$id}' matches multiple sidecars; pass --scope to disambiguate");
        }

        $sidecarPath = $matches[0];
        $metadata = $this->readSidecar($sidecarPath);

        // Named, and every field listed: a positional copy silently dropped whatever the DTO
        // grew since it was written (it had already lost failureReason and dumpBytes, and would
        // have lost the checksum fields next), while the docblock kept promising the opposite.
        $rewritten = new BackupMetadata(
            id: $metadata->id,
            createdAt: $createdAt->format(DateTimeInterface::ATOM),
            env: $metadata->env,
            scope: $metadata->scope,
            connections: $metadata->connections,
            sizeBytes: $metadata->sizeBytes,
            durationSeconds: $metadata->durationSeconds,
            keep: $metadata->keep,
            status: $metadata->status,
            warnings: $metadata->warnings,
            failureReason: $metadata->failureReason,
            dumpBytes: $metadata->dumpBytes,
            sha256: $metadata->sha256,
            verifiedAt: $metadata->verifiedAt,
            verifyOutcome: $metadata->verifyOutcome,
        );

        $this->publishAtomically($sidecarPath, $rewritten->toArray());

        return $sidecarPath;
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
        try {
            $raw = FsPath::read($sidecarPath);
        } catch (FsException $failure) {
            throw new BackupException("Backup sidecar not found: {$sidecarPath}", 0, $failure);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BackupException("Backup sidecar is not valid JSON: {$sidecarPath}");
        }

        return BackupMetadata::fromArray($decoded);
    }

    /**
     * Writes JSON to a same-directory temp file, then publishes it over the target through the Fs seam.
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

        try {
            FsPath::write($tmpPath, $json);
        } catch (FsException $failure) {
            throw new BackupException("Failed to write {$tmpPath}", 0, $failure);
        }

        try {
            FsPath::publish($tmpPath, $path);
        } catch (FsException $failure) {
            throw new BackupException("Failed to publish {$path}", 0, $failure);
        }
    }
}
