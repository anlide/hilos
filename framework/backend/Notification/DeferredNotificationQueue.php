<?php

declare(strict_types=1);

namespace Hilos\Notification;

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
 * So the draft is left here instead, and {@see AbstractNotificationsLibraryAgent} drains the file
 * when it starts. In ordinary life the queue is empty and nothing ever reads or writes it: only an
 * emit that happens with the node frozen or the daemon down comes this way.
 *
 * **A line, not a document.** One JSON object per line, in the vocabulary the emit signal already
 * speaks ({@see NotificationEmitSignalData}), because appending a line is the whole of what a
 * writer with no reader can safely do - two processes may be leaving notices here at once, and
 * neither can rewrite what the other put down.
 *
 * **Taken before it is read.** A drain renames the file aside and reads that, so a notice appended
 * while the library is starting is not swallowed by the delete. What survives a crash mid-drain is
 * the renamed file, and the next drain takes it first; what does not survive is a notice already
 * read out of it, which is the one loss this queue accepts and the reason its contents are notices
 * rather than facts.
 *
 * It lives beside the archives, under `BACKUP_DIR`: everything that queues here is part of a
 * restore, and an installation that names no backup directory runs no restores to have a letter
 * about. That is why an unset value is not an error here but an empty queue.
 */
final class DeferredNotificationQueue
{
    /** @var string Name of the queue file inside the backup directory */
    public const string FILE_NAME = 'pending-notifications.jsonl';

    /** @var string Suffix of the file a drain reads, renamed aside so a concurrent append is not lost */
    private const string TAKEN_SUFFIX = '.taken';

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
     * Takes everything waiting and hands it over, leaving the queue empty.
     *
     * A line that cannot be understood is logged and dropped rather than stopping the drain: it is
     * one letter, and the ones behind it in the file are owed to somebody too.
     *
     * @return list<NotificationDraft> Drafts left while nobody could deliver them, in the order they were left
     */
    public static function drain(): array
    {
        $path = self::path();
        if ($path === null) {
            return [];
        }

        // Leftovers first: a drain that died between the rename and the sending left its file
        // behind, and what is in it is still owed to somebody.
        $drafts = self::takeFile($path . self::TAKEN_SUFFIX);

        try {
            FsPath::move($path, $path . self::TAKEN_SUFFIX);
        } catch (FileMoveException) {
            // Nothing waiting, which is the ordinary case: the queue only fills during a restore.
            return $drafts;
        }

        return [...$drafts, ...self::takeFile($path . self::TAKEN_SUFFIX)];
    }

    /**
     * Reads one queue file whole and removes it.
     *
     * @param string $path Absolute path of the file to take
     * @return list<NotificationDraft> Drafts it carried, in file order
     */
    private static function takeFile(string $path): array
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

            return $drafts;
        }

        try {
            FsPath::delete($path);
        } catch (FileDeleteException $e) {
            // The letters are already in hand, so the run goes on; what is left behind is a file
            // the next drain would read a second time, and that is worth a line in the log.
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred notifications at {$path} were sent but the file could not be removed: {$e->getMessage()}",
            );
        }

        return $drafts;
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
     * Where the queue lives, or nowhere when this installation keeps no backups.
     *
     * @return ?string Absolute path of the queue file, or null when no backup directory is named
     */
    private static function path(): ?string
    {
        try {
            $directory = Hilos::$env?->string(EnvConstants::BACKUP_DIR);
        } catch (EnvException) {
            return null;
        }

        return $directory === null || $directory === ''
            ? null
            : rtrim($directory, '/') . '/' . self::FILE_NAME;
    }
}
