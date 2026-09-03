<?php

declare(strict_types=1);

namespace Hilos\Backup\Ship;

/**
 * BackupShipStep - what one spawned transfer is doing.
 *
 * Four steps and no more, because the destination is a mirror of the local store: two of them put
 * a backup there in the order the local store publishes it, the third takes away what the local
 * store no longer has, and the first transfers nothing at all - it is the local preparation of
 * what the others carry. It is a step of the pass rather than work done inline because encrypting
 * gigabytes is as long a child as sending them, and it has to be polled by the same code under
 * the same timeout instead of blocking the agent's tick.
 */
enum BackupShipStep: string
{
    /**
     * Encrypting the archive into a staged ciphertext, which goes first when a recipient set is
     * configured and does not happen at all when none is ({@see BackupArchiveEncryptor}).
     */
    case ENCRYPT_ARCHIVE = 'encrypt-archive';

    /** Copying the archive, which always goes first among the transfers. */
    case PUSH_ARCHIVE = 'push-archive';

    /**
     * Copying the sidecar, which goes second.
     *
     * The remote publish order mirrors the local one for the same reason: a sidecar without its
     * archive is an anomaly the read path already knows how to name, while an archive without a
     * sidecar carries no scope, no digest and no migration index - so an interrupted transfer can
     * never leave behind something that reads as a finished copy.
     */
    case PUSH_SIDECAR = 'push-sidecar';

    /** Re-stating a whole scope directory, so what was deleted here is deleted there. */
    case MIRROR = 'mirror';
}
