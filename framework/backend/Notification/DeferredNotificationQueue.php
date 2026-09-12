<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\DeferredQueueHandover;
use Hilos\Backup\RestoreNotifier;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;
use JsonException;

/**
 * DeferredNotificationQueue - notices written where nobody could be told yet (HIL-771).
 *
 * The emit seam is a door to the notifications library now ({@see HilosNotifier::emit()}), and a
 * door needs somebody on the other side of it. Two moments have nobody: a node under a restore
 * freeze, where every agent but the initiator is stopped, and a cold CLI restore, where the daemon
 * is down and the process holds no signal router at all. Both belong to the same story - the
 * restore outcome letter ({@see RestoreNotifier}) - and both would otherwise emit into silence.
 *
 * So the draft is left here instead, and the agent holding the backup directory ({@see BackupAgent})
 * offers it to {@see AbstractNotificationsLibraryAgent} until the library answers
 * ({@see DeferredQueueHandover}). The library never reads this file: in a cluster it may run on a
 * node whose disk the file is not on (HIL-846). In ordinary life the queue is empty - only an emit
 * that happens with the node frozen or the daemon down comes this way.
 *
 * **A line, not a document.** One JSON object per line, in the vocabulary the emit signal already
 * speaks ({@see NotificationEmitSignalData}), because appending a line is the whole of what a
 * writer with no reader can safely do - two processes may be leaving notices here at once, and
 * neither can rewrite what the other put down.
 *
 * **Released by receipt, not by reading.** A take renames the fresh file aside as a batch, so a
 * notice appended while the batch is in flight is not swallowed by its removal, and the batch file
 * stays until the library's receipt names it. The price runs the other way from a loss: a batch
 * whose receipt went missing is offered again, so a letter may reach its recipient twice - which is
 * the reason its contents are notices rather than facts.
 *
 * It lives beside the archives, under `BACKUP_DIR`: everything that queues here is part of a
 * restore, and an installation that names no backup directory runs no restores to have a letter
 * about. That is why an unset value is not an error here but an empty queue.
 */
final class DeferredNotificationQueue
{
    /** @var string Name of the queue file inside the backup directory */
    public const string FILE_NAME = 'pending-notifications.jsonl';

    /** @var string Suffix of a batch file, renamed aside so an append made while it is in flight is not lost */
    private const string TAKEN_SUFFIX = '.taken';

    /** @var string Separator between the queue file name and the batch id in the name of a batch file */
    private const string BATCH_SEPARATOR = '.';

    /** @var int Random bytes a batch id is drawn from */
    private const int BATCH_BYTES = 4;

    /** @var string Agent id the queue's own failures are logged under */
    private const string LOG_AGENT_ID = 'notifications';

    /**
     * Leaves one notice for the library to send when it is running again.
     *
     * Best-effort by construction: this is called on the failure paths of a restore, and a letter
     * that cannot be written down must not become the reason the restore is reported as broken.
     * What goes wrong is logged and swallowed.
     *
     * @param NotificationDraft $draft The notification nobody can deliver yet
     */
    public static function defer(NotificationDraft $draft): void
    {
        $path = self::path();
        if ($path === null) {
            return;
        }

        try {
            $line = json_encode(
                NotificationEmitSignalData::fromDraft($draft)->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
            FsPath::append($path, $line . "\n");
        } catch (JsonException | FileWriteException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred notification could not be queued at {$path}: {$e->getMessage()}",
            );
        }
    }

    /**
     * Sets a batch aside for the notifications library, leaving its file in place.
     *
     * A batch already in flight is handed out again before anything fresh is taken: nobody has
     * said it arrived, and what is in it is still owed to somebody. Only when none is in flight
     * does the fresh file become the next batch, under a new id; whatever is appended after that
     * waits for the batch ahead of it to be released, so the notices keep their order.
     *
     * The file is not removed here. It stays until the library's receipt names its batch
     * ({@see release()}), which makes the hand-over at-least-once: a batch whose receipt is lost
     * is offered again, and a letter in it may reach its recipient twice. For a letter about the
     * outcome of a restore a duplicate is the smaller harm than silence.
     *
     * The id only has to differ from the id of another batch in the same directory, and nobody
     * gains anything by guessing it, so it is drawn from the tolerant random axis.
     *
     * A line that cannot be understood is logged and dropped rather than stopping the read: it is
     * one letter, and the ones behind it in the file are owed to somebody too.
     *
     * @return ?DeferredNotificationBatch The batch to hand over, or null when nothing is waiting
     */
    public static function take(): ?DeferredNotificationBatch
    {
        $path = self::path();
        if ($path === null) {
            return null;
        }

        $batch = self::batchInFlight($path);
        if ($batch === null) {
            $batch = RandomHelper::hex(self::BATCH_BYTES);
            try {
                FsPath::move($path, self::batchPath($path, $batch));
            } catch (FileMoveException) {
                // Nothing waiting, which is the ordinary case: the queue only fills during a restore.
                return null;
            }
        }

        return new DeferredNotificationBatch($batch, self::readBatch(self::batchPath($path, $batch)));
    }

    /**
     * Forgets a batch the library has taken, by removing the file the batch is named by.
     *
     * A receipt for a batch whose file is already gone - the second receipt for a batch offered
     * twice - removes nothing and says nothing, and neither does an id that is not a batch id at
     * all: neither names a file this queue holds.
     *
     * @param string $batch Id of the batch the library's receipt is for
     */
    public static function release(string $batch): void
    {
        $path = self::path();
        if ($path === null || !ctype_xdigit($batch)) {
            return;
        }

        try {
            FsPath::delete(self::batchPath($path, $batch));
        } catch (FileDeleteException $e) {
            // The letters are with the library already; what is left behind is a batch the holder
            // offers a second time, and that is worth a line in the log.
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred notification batch {$batch} was taken but its file could not be removed: {$e->getMessage()}",
            );
        }
    }

    /**
     * Turns one queued line back into a draft, or into a log line.
     *
     * @param string $path File the line came from, named in the log
     * @param string $line One line as the file holds it, line ending included
     * @return ?NotificationDraft The draft, or null when the line is not one
     */
    private static function readLine(string $path, string $line): ?NotificationDraft
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new InvalidFormatException('queued line is not an object');
            }

            return NotificationEmitSignalData::fromArray($decoded)->toDraft();
        } catch (JsonException | InvalidFormatException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred notification at {$path} is not a notification and was dropped: {$e->getMessage()}",
            );

            return null;
        }
    }

    /**
     * Reads the file of one batch whole, leaving it where it is.
     *
     * @param string $path Absolute path of the batch file
     * @return list<NotificationDraft> Drafts it carries, in file order
     */
    private static function readBatch(string $path): array
    {
        $drafts = [];

        try {
            foreach (FsPath::readLines($path) as $line) {
                $draft = self::readLine($path, $line);
                if ($draft !== null) {
                    $drafts[] = $draft;
                }
            }
        } catch (FileNotFoundException) {
            return [];
        } catch (FileReadException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred notifications at {$path} could not be read: {$e->getMessage()}",
            );
        }

        return $drafts;
    }

    /**
     * Finds the batch an earlier take set aside and nobody has released yet.
     *
     * @param string $path Absolute path of the queue file
     * @return ?string Id of the batch in flight, or null when there is none
     */
    private static function batchInFlight(string $path): ?string
    {
        $prefix = $path . self::BATCH_SEPARATOR;
        foreach (glob($prefix . '*' . self::TAKEN_SUFFIX) ?: [] as $batchPath) {
            $batch = substr($batchPath, strlen($prefix), -strlen(self::TAKEN_SUFFIX));
            if (ctype_xdigit($batch)) {
                return $batch;
            }
        }

        return null;
    }

    /**
     * @param string $path Absolute path of the queue file
     * @param string $batch Batch id
     * @return string Absolute path of the file that batch is named by
     */
    private static function batchPath(string $path, string $batch): string
    {
        return $path . self::BATCH_SEPARATOR . $batch . self::TAKEN_SUFFIX;
    }

    /**
     * Where the queue lives, or nowhere when this installation keeps no backups.
     *
     * @return ?string Absolute path of the queue file, or null when no backup directory is named
     */
    private static function path(): ?string
    {
        $env = Hilos::$env;
        if ($env === null) {
            return null;
        }

        try {
            $directory = $env[EnvConstants::BACKUP_DIR]->string();
        } catch (EnvException) {
            return null;
        }

        return $directory === '' ? null : rtrim($directory, '/') . '/' . self::FILE_NAME;
    }
}
