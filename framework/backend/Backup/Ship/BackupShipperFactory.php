<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvKeyInvalidException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Hilos;
use Hilos\Tables\Backup\HilosBackupHistoryTable;

/**
 * BackupShipperFactory - picks the driver a parsed destination is served by.
 *
 * The one place a scheme name meets a class, so the recipes stay in the framework and a project
 * configures a destination with values only. Null means "nothing can ship to this destination":
 * an unrecognized scheme, or an ssh destination whose receiver is not pinned. The caller reports
 * it once and leaves shipping off, rather than failing per transfer forever.
 */
final class BackupShipperFactory
{
    /**
     * Builds the driver for a destination.
     *
     * An encryption key that is configured and unusable turns shipping off before the scheme is
     * even looked at, whichever scheme it is. The alternative is a clear copy leaving under a
     * setting that exists to stop exactly that, so there is no destination left to serve. Answering
     * here rather than at the transfer is also what makes the Copy column right for free: the
     * table asks this same method ({@see HilosBackupHistoryTable::shippingConfigured()}) and starts
     * reading such an installation as shipping nowhere.
     *
     * The ssh driver refuses to exist without {@see EnvConstants::BACKUP_SHIP_SSH_KNOWN_HOSTS}:
     * shipping is unattended, so there is nobody to answer the first-connection prompt, and the
     * two ways out of that would each be worse - accepting an unknown host key ships the database
     * to whoever answers, and a per-transfer failure hides a configuration mistake behind a retry
     * loop. A missing key file is not checked here: an agent-forwarded or default identity is a
     * legitimate setup, and ssh alone can tell.
     *
     * @param BackupShipTarget $target Parsed destination
     * @return ?BackupShipperInterface Driver for the destination, or null when nothing serves it
     * @throws EnvInvalidValueException When a configured credential path cannot be read as a string
     * @throws EnvKeyInvalidException When an environment key is malformed
     * @throws EnvNotInCatalogException When the project's catalog declares no ssh credentials
     * @throws EnvTypeMismatchException When the catalog declares a credential path as another type
     * @throws MissingEnvironmentVariableException When a credential path is required and unset
     */
    public static function fromTarget(BackupShipTarget $target): ?BackupShipperInterface
    {
        if (BackupArchiveEncryptor::isConfigured() && BackupArchiveEncryptor::fromEnv() === null) {
            return null;
        }

        if ($target->scheme === BackupShipTarget::SCHEME_FILE) {
            return new LocalBackupShipper($target);
        }

        if ($target->scheme !== BackupShipTarget::SCHEME_SSH) {
            return null;
        }

        $knownHosts = Hilos::$env->string(EnvConstants::BACKUP_SHIP_SSH_KNOWN_HOSTS);
        if ($knownHosts === '') {
            return null;
        }

        return new SshBackupShipper($target, Hilos::$env->string(EnvConstants::BACKUP_SHIP_SSH_KEY), $knownHosts);
    }
}
