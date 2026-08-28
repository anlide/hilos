<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Backup\Agent\BackupAgent;
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
use Hilos\Utils\Logger;
use JsonException;

/**
 * DeferredSessionCarryoverQueue - logins photographed before a restore, waiting for their owner
 * (HIL-479, HIL-771).
 *
 * The sessions table belongs to {@see AbstractSessionsLibraryAgent}, so the restore stopped
 * writing it: {@see BackupAgent} photographs the live logins before the swap and leaves the
 * picture here, and the library re-creates the rows when it comes back up. What made this a file
 * rather than a frame is the moment it happens in - the node is still frozen, every agent but the
 * restore's own is stopped, and a frame addressed to a stopped agent under a freeze is dropped
 * where it is sent. So the picture is left somewhere the freeze cannot reach, and picked up by
 * the one process allowed to write those rows.
 *
 * **A line, not a document.** One JSON object per session, in the vocabulary {@see SessionCarryover}
 * already speaks, because appending a line is the whole of what a writer with no reader can safely
 * do.
 *
 * **Taken before it is read.** A drain renames the file aside and reads that, so a session
 * appended while the library is starting is not swallowed by the delete. What survives a crash
 * mid-drain is the renamed file, and the next drain takes it first.
 *
 * It lives beside the archives, under `BACKUP_DIR`, because everything that queues here is part of
 * a restore - an installation that names no backup directory runs none, which is why an unset
 * value is an empty queue rather than an error.
 */
final class DeferredSessionCarryoverQueue
{
    /** @var string Name of the queue file inside the backup directory */
    public const string FILE_NAME = 'pending-session-carryover.jsonl';

    /** @var string Suffix of the file a drain reads, renamed aside so a concurrent append is not lost */
    private const string TAKEN_SUFFIX = '.taken';

    /** @var string Agent id the queue's own failures are logged under */
    private const string LOG_AGENT_ID = 'sessions';

    /**
     * Leaves the photographed logins for the library to re-create when it is running again.
     *
     * Best-effort by construction, exactly as the write it replaces was: a restore that has
     * already succeeded is not undone, and the freeze is not held, because the logins could not be
     * written down. What goes wrong is logged and swallowed.
     *
     * The count it returns is what the caller owes: a restore reports it to its own master so the
     * lift of the freeze waits for these logins to be back before it tells the browsers to reload
     * (HIL-771). Zero on every path that queued nothing - an empty snapshot, an installation with
     * no backup directory, a write that failed - because a debt nobody can pay would hold the lift
     * for its whole timeout and say the wrong thing in the log.
     *
     * @param list<SessionCarryover> $snapshot Sessions captured before the database was replaced
     * @return int Sessions actually left in the queue
     */
    public static function defer(array $snapshot): int
    {
        if ($snapshot === []) {
            return 0;
        }

        $path = self::path();
        if ($path === null) {
            return 0;
        }

        try {
            $lines = '';
            foreach ($snapshot as $carryover) {
                $lines .= json_encode(
                    self::toArray($carryover),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ) . "\n";
            }
            FsPath::append($path, $lines);
        } catch (JsonException | FileWriteException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred session carry-over could not be queued at {$path}: {$e->getMessage()}",
            );

            return 0;
        }

        return count($snapshot);
    }

    /**
     * Takes everything waiting and hands it over, leaving the queue empty.
     *
     * A line that cannot be understood is logged and dropped rather than stopping the drain: it is
     * one login, and the ones behind it in the file belong to somebody too.
     *
     * @return list<SessionCarryover> Sessions left for the library, in the order they were left
     */
    public static function drain(): array
    {
        $path = self::path();
        if ($path === null) {
            return [];
        }

        // Leftovers first: a drain that died between the rename and the write left its file
        // behind, and the logins in it are still owed to the people holding them.
        $carried = self::takeFile($path . self::TAKEN_SUFFIX);

        try {
            FsPath::move($path, $path . self::TAKEN_SUFFIX);
        } catch (FileMoveException) {
            // Nothing waiting, which is the ordinary case: the queue only fills during a restore.
            return $carried;
        }

        return [...$carried, ...self::takeFile($path . self::TAKEN_SUFFIX)];
    }

    /**
     * Reads one queue file whole and removes it.
     *
     * @param string $path Absolute path of the file to take
     * @return list<SessionCarryover> Sessions it carried, in file order
     */
    private static function takeFile(string $path): array
    {
        $carried = [];

        try {
            foreach (FsPath::readLines($path) as $line) {
                $carryover = self::readLine($path, $line);
                if ($carryover !== null) {
                    $carried[] = $carryover;
                }
            }
        } catch (FileNotFoundException) {
            return [];
        } catch (FileReadException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred session carry-over at {$path} could not be read: {$e->getMessage()}",
            );

            return $carried;
        }

        try {
            FsPath::delete($path);
        } catch (FileDeleteException $e) {
            // The logins are already in hand, so the start goes on; what is left behind is a file
            // the next drain would read a second time, which the carry-over itself survives - a
            // token that already holds a row is neither carried nor lost - but is worth a log line.
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred session carry-over at {$path} was applied but the file could not be removed: {$e->getMessage()}",
            );
        }

        return $carried;
    }

    /**
     * Turns one queued line back into a captured session, or into a log line.
     *
     * @param string $path File the line came from, named in the log
     * @param string $line One line as the file holds it, line ending included
     * @return ?SessionCarryover The captured session, or null when the line is not one
     */
    private static function readLine(string $path, string $line): ?SessionCarryover
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

            return self::fromArray($decoded);
        } catch (JsonException | InvalidFormatException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred session carry-over at {$path} is not a session and was dropped: {$e->getMessage()}",
            );

            return null;
        }
    }

    /**
     * Writes one captured session as the line the queue holds.
     *
     * @param SessionCarryover $carryover Session captured before the swap
     * @return array<string, mixed> Line payload
     */
    private static function toArray(SessionCarryover $carryover): array
    {
        $identities = [];
        foreach ($carryover->identities as $identity) {
            $identities[] = ['type' => $identity->type, 'identifier' => $identity->identifier];
        }

        return [
            'token' => $carryover->token,
            'createdAt' => $carryover->createdAt,
            'expiresAt' => $carryover->expiresAt,
            'identities' => $identities,
        ];
    }

    /**
     * Reads one queued line back into the shape the carry-over works on.
     *
     * A session with no identity pairs left is kept rather than refused: the carry-over already
     * answers that case by dropping it, counted and logged, and refusing it here would turn a
     * countable loss into a line the drain complains about.
     *
     * @param array<string, mixed> $data One decoded line
     * @return SessionCarryover The captured session
     * @throws InvalidFormatException When the line names no token, no creation time, or bad identities
     */
    private static function fromArray(array $data): SessionCarryover
    {
        $token = $data['token'] ?? null;
        $createdAt = $data['createdAt'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;
        $identities = $data['identities'] ?? null;
        if (!is_string($token) || $token === '' || !is_string($createdAt) || $createdAt === '') {
            throw new InvalidFormatException('queued session names no token or no creation time');
        }

        if ($expiresAt !== null && !is_string($expiresAt)) {
            throw new InvalidFormatException('queued session carries a malformed expiry');
        }

        if (!is_array($identities)) {
            throw new InvalidFormatException('queued session carries no identity list');
        }

        return new SessionCarryover(
            token: $token,
            createdAt: $createdAt,
            expiresAt: $expiresAt,
            identities: self::identitiesFromArray($identities),
        );
    }

    /**
     * Reads the identity pairs of one queued line.
     *
     * @param array<mixed> $identities Identity list as the line holds it
     * @return list<SessionIdentityRef> Pairs the session may be recognized by
     * @throws InvalidFormatException When a pair is not one
     */
    private static function identitiesFromArray(array $identities): array
    {
        $refs = [];
        foreach ($identities as $identity) {
            $type = is_array($identity) ? $identity['type'] ?? null : null;
            $identifier = is_array($identity) ? $identity['identifier'] ?? null : null;
            if (!is_string($type) || $type === '' || !is_string($identifier) || $identifier === '') {
                throw new InvalidFormatException('queued session carries a malformed identity pair');
            }

            $refs[] = new SessionIdentityRef($type, $identifier);
        }

        return $refs;
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
