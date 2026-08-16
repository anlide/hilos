<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

use Hilos\Backup\BackupShipState;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Hilos;

/**
 * BackupShipTarget - the parsed destination backups are copied to, one per installation.
 *
 * The framework carries the recipes (the driver classes); the project supplies only the URL,
 * the same shape every other external provider is configured with. Two schemes live here:
 * `ssh://<user>@<host>[:<port>]/<absolute-path>` and `file:///<absolute-path>`.
 *
 * A URL that does not parse yields null rather than an exception, exactly as an empty one does.
 * The destination is read on a tick of a monopoly agent that also runs the schedule and the
 * restore: a throw there would take those down over a typo in a deployment value, while a null
 * lands in the state the operator already understands - shipping is off, and the Copy column
 * says {@see BackupShipState::NONE}.
 */
final class BackupShipTarget
{
    /** Scheme copying over rsync-over-ssh to another machine. */
    public const string SCHEME_SSH = 'ssh';

    /** Scheme mirroring into a local directory, which also covers a mounted network share. */
    public const string SCHEME_FILE = 'file';

    /** Port the ssh scheme uses when the URL names none. */
    public const int DEFAULT_SSH_PORT = 22;

    /**
     * @param string $scheme One of {@see SCHEME_SSH}, {@see SCHEME_FILE}
     * @param string $user Remote login for the ssh scheme; empty for the file scheme
     * @param string $host Receiver host for the ssh scheme; empty for the file scheme
     * @param int $port Receiver ssh port; 0 for the file scheme
     * @param string $path Absolute path of the destination root on the receiver
     */
    public function __construct(
        public readonly string $scheme,
        public readonly string $user,
        public readonly string $host,
        public readonly int $port,
        public readonly string $path,
    ) {
    }

    /**
     * Reads the configured destination.
     *
     * @return ?self Parsed destination, or null when none is configured or the URL is malformed
     * @throws EnvInvalidValueException When the configured value cannot be read as a string
     * @throws EnvKeyInvalidException When the environment key is malformed
     * @throws EnvNotInCatalogException When the project's catalog declares no shipping destination
     * @throws EnvTypeMismatchException When the catalog declares the destination as another type
     * @throws MissingEnvironmentVariableException When the destination is required and unset
     */
    public static function fromEnv(): ?self
    {
        return self::parse(Hilos::$env->string(EnvConstants::BACKUP_SHIP_TARGET));
    }

    /**
     * Parses a destination URL.
     *
     * The path has to be absolute in both schemes: a relative destination would resolve against
     * whatever directory the transfer binary happened to start in, which for the ssh scheme is
     * the receiver's home and for the file scheme is the daemon's working directory - two
     * different places for one written value.
     *
     * @param string $url Destination URL, empty when shipping is off
     * @return ?self Parsed destination, or null when the URL is empty or malformed
     */
    public static function parse(string $url): ?self
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        // external-boundary: the URL comes from a deployment value and may be anything at all
        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/')) {
            return null;
        }

        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        return match ($parts['scheme'] ?? null) {
            self::SCHEME_SSH => self::parseSsh($parts, $path),
            // A host under the file scheme means the authority was left out by one slash
            // (`file://srv/backups`), which silently turns the first path segment into a host and
            // the destination into a different, shorter path than the one that was written.
            self::SCHEME_FILE => isset($parts['host']) ? null : new self(self::SCHEME_FILE, '', '', 0, $path),
            default => null,
        };
    }

    /**
     * Builds the ssh destination from an already split URL.
     *
     * Both the login and the host are required: rsync would accept a bare host and fall back to
     * the local account name, but that account belongs to the daemon and is not the one the
     * receiver was set up for, so an accidental omission would fail per transfer rather than at
     * the destination.
     *
     * @param array<string, mixed> $parts Result of `parse_url()` on the destination URL
     * @param string $path Absolute destination path, already trimmed of trailing slashes
     * @return ?self Parsed ssh destination, or null when the URL names no login or host
     */
    private static function parseSsh(array $parts, string $path): ?self
    {
        $user = $parts['user'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($user) || $user === '' || !is_string($host) || $host === '') {
            return null;
        }

        return new self(self::SCHEME_SSH, $user, $host, (int)($parts['port'] ?? self::DEFAULT_SSH_PORT), $path);
    }
}
