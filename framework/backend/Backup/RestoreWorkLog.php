<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Constants\EnvConstants;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Log\DaemonLogAddress;
use Hilos\Utils\Helpers\TimeHelper;

/** Mandatory restore evidence in the existing operation stream, independent of Logger's level. */
final class RestoreWorkLog
{
    /**
     * @return ?string Canonical operation stream, or null when unconfigured
     */
    public static function address(): ?string
    {
        return DaemonLogAddress::configured(EnvConstants::DAEMON_LOG_FILE);
    }

    /**
     * @param string $archivePath Archive selected for this invocation
     * @param FullRestorePlan $plan Checked destinations and the original primary marker
     * @throws RestoreFailedException When the required evidence cannot be appended
     */
    public static function begin(string $archivePath, FullRestorePlan $plan): void
    {
        // Serialization boundary: these records contain no credentials, dump data or SQL diagnostics.
        $targets = [];
        foreach ($plan->targets as $target) {
            $targets[] = [
                'index' => $target->connection->index,
                'host' => $target->config->host,
                'port' => $target->config->port,
                'socket' => $target->config->socket,
                'database' => $target->config->database,
                'server' => $target->address,
            ];
        }
        self::append([
            'archive' => $archivePath,
            'targets' => $targets,
            'primaryMarker' => $plan->marker?->marker,
            'step' => 'prepared; no database write started',
        ]);
    }

    /**
     * Records intent before work, never a claim that the step completed.
     *
     * @param string $id Backup id
     * @param RestorePhase $phase Existing restore phase
     * @param list<int> $indices Connections the impending step can affect
     * @param string $step Impending work
     * @throws RestoreFailedException When the operation stream cannot be appended
     */
    public static function starting(string $id, RestorePhase $phase, array $indices, string $step): void
    {
        self::append(['id' => $id, 'phase' => $phase->value, 'connections' => $indices, 'starting' => $step]);
    }

    /**
     * @param array<string, mixed> $record Log serialization boundary
     * @throws RestoreFailedException When the configured stream is absent or cannot be written
     */
    private static function append(array $record): void
    {
        $path = self::address();
        if ($path === null || $path === '') {
            throw new RestoreFailedException('The restore operation log (DAEMON_LOG_FILE) is not configured');
        }
        $stamp = TimeHelper::getTimestampWithMs();
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        try {
            FsPath::append($path, '[' . $stamp . '] Restore: ' . $line . "\n");
        } catch (FsException $failure) {
            throw new RestoreFailedException('Cannot write the restore operation log: ' . $path, 0, $failure);
        }
    }
}
