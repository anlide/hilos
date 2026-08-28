<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\ProtectedMode\Exception\ProtectedModeFreezeUnreadableException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use JsonException;

/**
 * ProtectedModeFreezeStore - the freeze this node carries, kept where a restarting daemon finds it.
 *
 * The freeze row lives in runtime state, which is memory only, so a daemon that goes down under a
 * freeze comes back with the row inactive and serves clients over a database its restore never
 * finished writing - the single failure protected mode exists to prevent, reached by the mundane
 * route of a restart. This class is the whole of the answer: each node's master writes its own row
 * here on every phase change ({@see DaemonProtectedModeExecutor}), deletes it when the phase reaches
 * inactive, and the daemon reads it during startup before any server binds
 * ({@see DaemonManager::boot()}).
 *
 * **A file that exists and cannot be understood refuses the startup.** Absent is not the same thing
 * as unreadable: a node that was never frozen leaves nothing behind, while a file that is there says
 * a freeze was standing when the daemon went down. Reading a damaged one as "no freeze" would open
 * the node on the strength of a parse failure, so it raises
 * {@see ProtectedModeFreezeUnreadableException} instead and the daemon does not start.
 *
 * **The write is published rather than streamed into place.** The payload is small, but the crash
 * this file exists for is exactly the one that can interrupt a write, and a half-written file would
 * turn a survivable restart into a refused one. {@see FsPath::publish()} fsyncs a temp file beside
 * the target and renames it over, so a reader sees either the previous freeze or the new one.
 *
 * The path is derived from `DAEMON_LOG_FILE` rather than declared by an env value of its own: that
 * variable is required in every installation and its directory is provably writable (the daemon
 * writes its log there), so a store addressed by it needs no configuration that could be missing on
 * the one node that ends up needing it. {@see DockerManager} derives its own path the same way.
 */
final class ProtectedModeFreezeStore
{
    /** @var string Name of the freeze file inside the daemon log directory */
    public const string FILE_NAME = 'protected-mode.state.json';

    /** @var string Suffix of the temp file the write is published from, beside the target */
    private const string TEMP_SUFFIX = '.tmp';

    /**
     * @var int Shape of the file this build writes and accepts
     *
     * Read back on load and refused when it differs: a build that does not understand what it finds
     * must say so rather than restore a freeze by guessing at its fields.
     */
    private const int FORMAT_VERSION = 1;

    /** @var string File key carrying {@see FORMAT_VERSION} */
    private const string KEY_VERSION = 'version';

    /** @var string File key carrying the freeze row */
    private const string KEY_ROW = 'row';

    /**
     * Writes the freeze standing on this node over whatever was there before.
     *
     * @param array<string, mixed> $row Freeze row as {@see ProtectedModeRuntime::toArray()} renders it
     * @throws EnvException When the daemon log path the store lives beside cannot be read
     * @throws FileMoveException When the finished file cannot be moved into place
     * @throws FileWriteException When the file cannot be written
     * @throws JsonException When the row cannot be encoded
     */
    public function save(array $row): void
    {
        $path = self::path();
        $payload = json_encode([
            self::KEY_VERSION => self::FORMAT_VERSION,
            self::KEY_ROW => $row,
        ], JSON_THROW_ON_ERROR);

        $tempPath = $path . self::TEMP_SUFFIX;
        FsPath::write($tempPath, $payload);
        FsPath::publish($tempPath, $path);
    }

    /**
     * Forgets the freeze: this node is open again and a restart must not bring one back.
     *
     * @throws EnvException When the daemon log path the store lives beside cannot be read
     * @throws FileDeleteException When the file is there and cannot be removed
     */
    public function forget(): void
    {
        FsPath::delete(self::path());
    }

    /**
     * Reads the freeze this node went down under, if it went down under one.
     *
     * @return ?ProtectedModeRuntime Freeze row left on disk, or null when this node was not frozen
     * @throws EnvException When the daemon log path the store lives beside cannot be read
     * @throws ProtectedModeFreezeUnreadableException When a file is there and cannot be understood
     */
    public function load(): ?ProtectedModeRuntime
    {
        $path = self::path();

        try {
            $raw = FsPath::read($path);
        } catch (FileNotFoundException) {
            return null;
        } catch (FileReadException $e) {
            throw new ProtectedModeFreezeUnreadableException(
                "Protected mode: the freeze left at {$path} cannot be read: {$e->getMessage()}",
                previous: $e,
            );
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProtectedModeFreezeUnreadableException(
                "Protected mode: the freeze left at {$path} is not valid JSON: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (!is_array($decoded) || ($decoded[self::KEY_VERSION] ?? null) !== self::FORMAT_VERSION) {
            throw new ProtectedModeFreezeUnreadableException(
                "Protected mode: the freeze left at {$path} is not a version " . self::FORMAT_VERSION . ' freeze file',
            );
        }

        $row = $decoded[self::KEY_ROW] ?? null;
        if (!is_array($row)) {
            throw new ProtectedModeFreezeUnreadableException(
                "Protected mode: the freeze left at {$path} carries no row",
            );
        }

        try {
            return ProtectedModeRuntime::fromRow($row);
        } catch (InvalidFormatException $e) {
            throw new ProtectedModeFreezeUnreadableException(
                "Protected mode: the freeze left at {$path} carries a row this node cannot read: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Where the freeze file lives on this node.
     *
     * @return string Absolute path of the freeze file
     * @throws EnvException When the daemon log path cannot be read
     */
    private static function path(): string
    {
        return dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]) . '/' . self::FILE_NAME;
    }
}
