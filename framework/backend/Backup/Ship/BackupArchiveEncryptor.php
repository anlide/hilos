<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

use Hilos\Backup\BackupCreator;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;

/**
 * BackupArchiveEncryptor - turns the copy that leaves this machine into ciphertext.
 *
 * A driver seam shaped like the shippers beside it: it BUILDS the command and never runs it, so
 * every argument `age` will see is asserted in a unit test without a key, a link or a receiver.
 * The binary is external for the same reason `mysqldump`, `tar` and `rsync` are - an archive of
 * gigabytes cannot travel through the memory of the monopoly agent that is also running the
 * schedule and the restore - and the format is `age`'s own, so whoever holds the private key
 * decrypts the copy with a stranger's binary on a day this installation no longer exists.
 *
 * Only the copy is encrypted; the stored archive never is. The private half of the key does not
 * live on this machine at all, which is what makes a break-in unable to read what has already
 * left - and, deliberately, what makes a framework-side restore from the remote copy impossible.
 */
final class BackupArchiveEncryptor
{
    /** Encryption binary; the image requirement this seam adds to a deployment. */
    public const string BINARY = 'age';

    /**
     * Temp-name discriminator of the staged ciphertext, keeping it clear of the create path's own
     * `.tmp-<base>-<pid>` artifacts, which {@see BackupCreator} sweeps by that prefix alone.
     */
    public const string TEMP_KIND = 'shipenc';

    /**
     * How much of the recipient digest is kept. Enough that two deployed recipient sets do not
     * collide, short enough to sit in a sidecar and an index row as a readable mark; it names a
     * set this installation configured, not a secret, so nothing rests on its width.
     */
    private const int FINGERPRINT_LENGTH = 12;

    /**
     * @param string $recipientsPath Absolute path of the age recipients file
     * @param string $fingerprint Digest of the normalized recipient set
     */
    private function __construct(
        private readonly string $recipientsPath,
        private readonly string $fingerprint,
    ) {
    }

    /**
     * Builds the encryptor when a recipients file is configured AND usable.
     *
     * Null on every other reading, the unusable one included: the caller pairs it with
     * {@see self::isConfigured()} and turns shipping off altogether rather than letting a clear
     * copy leave, because that copy is the exposure the file was configured against.
     *
     * @return ?self Encryptor, or null when no usable recipient set is configured
     * @throws EnvInvalidValueException When the configured path cannot be read as a string
     * @throws EnvKeyInvalidException When the environment key is malformed
     * @throws EnvNotInCatalogException When the project's catalog declares no recipients path
     * @throws EnvTypeMismatchException When the catalog declares the path as another type
     * @throws MissingEnvironmentVariableException When the path is required and unset
     */
    public static function fromEnv(): ?self
    {
        $path = trim(Hilos::$env->string(EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS));
        if ($path === '') {
            return null;
        }

        try {
            $recipients = self::normalize(FsPath::read($path));
        } catch (FsException) {
            // A file that is configured and unreadable is a configuration state, not a crash: the
            // caller names it and leaves shipping off, the way an unpinned ssh receiver already is.
            return null;
        }

        if ($recipients === []) {
            return null;
        }

        return new self($path, substr(hash('sha256', implode("\n", $recipients)), 0, self::FINGERPRINT_LENGTH));
    }

    /**
     * Whether a recipients file is configured at all, however usable it turns out to be.
     *
     * The half of the pair that tells "this installation ships in the clear" from "this
     * installation was told to encrypt and cannot": the first is today's behaviour and ships, the
     * second ships nothing.
     *
     * @return bool True when the environment names a recipients file
     * @throws EnvInvalidValueException When the configured path cannot be read as a string
     * @throws EnvKeyInvalidException When the environment key is malformed
     * @throws EnvNotInCatalogException When the project's catalog declares no recipients path
     * @throws EnvTypeMismatchException When the catalog declares the path as another type
     * @throws MissingEnvironmentVariableException When the path is required and unset
     */
    public static function isConfigured(): bool
    {
        return trim(Hilos::$env->string(EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS)) !== '';
    }

    /**
     * Where the staged ciphertext of one archive is written.
     *
     * A DIRECTORY holding one file rather than a temp file with a suffix, because the push step
     * names the file on the receiver by the basename of what it sends: the ciphertext has to
     * carry the FINAL name already, and a name has room for only one of the two roles.
     *
     * @param string $scopeDir Absolute path of the local scope directory
     * @param string $base Archive base name, without the extension
     * @param int $pid Pid of the process staging it, keeping two runs apart
     * @return string Absolute path of the staging directory
     */
    public static function stageDir(string $scopeDir, string $base, int $pid): string
    {
        return self::stageDirPrefix($scopeDir, $base) . $pid;
    }

    /**
     * What every staging directory of one archive starts with, whichever process made it.
     *
     * The one spelling of the name, so the sweep that reclaims a dead pass's directory and the
     * call that creates a live one cannot drift apart: the pid is the only difference between
     * them, and a sweep looking for a prefix of its own would stop matching the day the name
     * changes.
     *
     * @param string $scopeDir Absolute path of the local scope directory
     * @param string $base Archive base name, without the extension
     * @return string Absolute path prefix the pid is appended to
     */
    public static function stageDirPrefix(string $scopeDir, string $base): string
    {
        return $scopeDir . '/' . BackupCreator::TEMP_PREFIX . self::TEMP_KIND . '-' . $base . '-';
    }

    /**
     * The staged ciphertext inside {@see self::stageDir()}, under the name the receiver will see.
     *
     * @param string $scopeDir Absolute path of the local scope directory
     * @param string $base Archive base name, without the extension
     * @param int $pid Pid of the process staging it
     * @return string Absolute path of the staged archive
     */
    public static function stagedArchivePath(string $scopeDir, string $base, int $pid): string
    {
        return self::stageDir($scopeDir, $base, $pid) . '/' . $base . BackupCreator::ARCHIVE_EXTENSION;
    }

    /**
     * The mark of the recipient set this copy is encrypted to.
     *
     * Recorded beside the shipping outcome, and compared with the mark a stored copy carries: that
     * comparison is the whole of what makes turning a key on, off, or over re-send the store,
     * without a migration command of its own.
     *
     * @return string Digest of the normalized recipient set
     */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * Builds the command encrypting one stored archive into a staged ciphertext.
     *
     * Built and not run, exactly as {@see BackupShipperInterface} builds its transfers: five argv
     * entries handed to the kernel with no shell in between, so nothing in a path can be read as
     * syntax.
     *
     * @param string $sourcePath Absolute path of the stored archive, which is left untouched
     * @param string $targetPath Absolute path the ciphertext is written to
     * @return BackupShipCommand Ready-to-spawn encryption command
     */
    public function encryptCommand(string $sourcePath, string $targetPath): BackupShipCommand
    {
        return new BackupShipCommand(self::BINARY, [
            '-R',
            $this->recipientsPath,
            '-o',
            $targetPath,
            $sourcePath,
        ]);
    }

    /**
     * Reduces a recipients file to the set it names.
     *
     * Blank lines and `#` comments are dropped and the rest is sorted, so the fingerprint answers
     * "which keys" and not "how the file is laid out": reordering the lines or annotating them
     * would otherwise re-send the whole store for nothing.
     *
     * @param string $contents Raw recipients file
     * @return list<string> Recipients, trimmed, sorted, and without comments
     */
    private static function normalize(string $contents): array
    {
        $recipients = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $recipients[] = $trimmed;
        }
        sort($recipients);

        return $recipients;
    }
}
