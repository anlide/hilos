<?php

declare(strict_types=1);

namespace Hilos\Backup\Exception;

use Hilos\Backup\BackupMetadata;

/**
 * Thrown when a backup sidecar carries no id, creation timestamp, or environment.
 *
 * These three fields address the backup everywhere it is used later: they form the runtime
 * history row id, the instant retention compares against, and part of the archive base name.
 * A sidecar without them is unreadable in the same sense as malformed JSON, so
 * {@see BackupMetadata::fromArray()} refuses it rather than restoring a record whose prune
 * and delete would quietly miss the file.
 */
final class BackupMetadataIncompleteException extends BackupException
{
}
