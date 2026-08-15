<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Notification\NotificationTypeRegistry;

/**
 * BackupNotificationType - the machine types a finished restore is announced under (HIL-279).
 *
 * Declared by the framework rather than by a project, because the code that emits them is the
 * framework's own: a restore ends inside {@see BackupAgent} or inside the cold CLI command, and
 * both raise the same two types. Both are registered as mandatory in
 * {@see NotificationTypeRegistry}: replacing the production database is not something an
 * administrator may silence in their personal channel settings.
 *
 * There are two of them and no more. The whole story of a run - which archive, under which scope,
 * who asked, when it started, how long it took, whether every process re-read the new database -
 * travels inside the one terminal notification, because a notification written before the import
 * is erased by the import itself.
 */
final class BackupNotificationType
{
    /** A restore replayed its archive and every process re-read the replaced database. */
    public const string RESTORE_SUCCEEDED = 'backup.restore.succeeded';

    /** A restore failed, or came back with the node still closed. */
    public const string RESTORE_FAILED = 'backup.restore.failed';
}
