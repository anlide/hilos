<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\DeferredSessionCarryoverHandoverSignalData;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\DeferredQueueHandover;
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
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;
use JsonException;

/**
 * DeferredSessionCarryoverQueue - logins photographed before a restore, waiting for their owner
 * (HIL-479, HIL-771).
 *
 * The sessions table belongs to {@see AbstractSessionsLibraryAgent}, so the restore stopped
 * writing it: {@see BackupAgent} photographs the live logins before the swap and leaves the
 * picture here. What made this a file rather than a frame is the moment it happens in - the node
 * is still frozen, every agent but the restore's own is stopped, and a frame addressed to a stopped
 * agent under a freeze is dropped where it is sent. So the picture is left somewhere the freeze
 * cannot reach, and the agent that left it goes on offering it to the library until the library
 * answers ({@see DeferredQueueHandover}). The library never reads this file: in a cluster it may
 * run on a node whose disk the file is not on (HIL-846).
 *
 * **A line, not a document.** One JSON object per session, in the vocabulary {@see SessionCarryover}
 * already speaks, because appending a line is the whole of what a writer with no reader can safely
 * do.
 *
 * **Released by receipt, not by reading.** A take renames the fresh file aside as a batch, so a
 * session appended while the batch is in flight is not swallowed by its removal, and the batch
 * file stays until the library's receipt names it. What survives a restart of the holder is the
 * batch file, and the next take hands it out again before anything fresh.
 *
 * It lives beside the archives, under `BACKUP_DIR`, because everything that queues here is part of
 * a restore - an installation that names no backup directory runs none, which is why an unset
 * value is an empty queue rather than an error.
 */
final class DeferredSessionCarryoverQueue
{
    /** @var string Name of the queue file inside the backup directory */
    public const string FILE_NAME = 'pending-session-carryover.jsonl';

    /** @var string Suffix of a batch file, renamed aside so an append made while it is in flight is not lost */
    private const string TAKEN_SUFFIX = '.taken';

    /** @var string Separator between the queue file name and the batch id in the name of a batch file */
    private const string BATCH_SEPARATOR = '.';

    /** @var int Random bytes a batch id is drawn from */
    private const int BATCH_BYTES = 4;

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
                    self::carryoverToArray($carryover),
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
     * Sets a batch aside for the owner of the sessions table, leaving its file in place.
     *
     * A batch already in flight is handed out again before anything fresh is taken: nobody has
     * said it arrived, and the logins in it are still owed to the people holding them. Only when
     * none is in flight does the fresh file become the next batch, under a new id; whatever is
     * appended after that waits for the batch ahead of it to be released, so the logins keep
     * their order.
     *
     * The file is not removed here. It stays until the owner's receipt names its batch
     * ({@see release()}), which makes the hand-over at-least-once: a batch whose receipt is lost
     * is offered again, and the carry-over survives the repeat - a token that already holds a row
     * is neither carried nor lost.
     *
     * The id only has to differ from the id of another batch in the same directory, and nobody
     * gains anything by guessing it, so it is drawn from the tolerant random axis.
     *
     * A line that cannot be understood is logged and dropped rather than stopping the read: it is
     * one login, and the ones behind it in the file belong to somebody too.
     *
     * @return ?DeferredSessionCarryoverBatch The batch to hand over, or null when nothing is waiting
     */
    public static function take(): ?DeferredSessionCarryoverBatch
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

        return new DeferredSessionCarryoverBatch($batch, self::readBatch(self::batchPath($path, $batch)));
    }

    /**
     * Forgets a batch its owner has taken, by removing the file the batch is named by.
     *
     * A receipt for a batch whose file is already gone - the second receipt for a batch offered
     * twice - removes nothing and says nothing, and neither does an id that is not a batch id at
     * all: neither names a file this queue holds.
     *
     * @param string $batch Id of the batch the owner's receipt is for
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
            // The logins are with their owner already; what is left behind is a batch the holder
            // offers a second time, which the carry-over survives but is worth a log line.
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred session carry-over batch {$batch} was taken but its file could not be removed: {$e->getMessage()}",
            );
        }
    }

    /**
     * Writes one captured session as the line the queue holds.
     *
     * Public because the hand-over carries the same line ({@see DeferredSessionCarryoverHandoverSignalData}):
     * one vocabulary for the file and the wire.
     *
     * @param SessionCarryover $carryover Session captured before the swap
     * @return array<string, mixed> Line payload
     */
    public static function carryoverToArray(SessionCarryover $carryover): array
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
     * countable loss into a line the read complains about.
     *
     * @param array<mixed> $data One decoded line
     * @return SessionCarryover The captured session
     * @throws InvalidFormatException When the line names no token, no creation time, or bad identities
     */
    public static function carryoverFromArray(array $data): SessionCarryover
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

            return self::carryoverFromArray($decoded);
        } catch (JsonException | InvalidFormatException $e) {
            Logger::logAgentError(
                self::LOG_AGENT_ID,
                "Deferred session carry-over at {$path} is not a session and was dropped: {$e->getMessage()}",
            );

            return null;
        }
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
     * Reads the file of one batch whole, leaving it where it is.
     *
     * @param string $path Absolute path of the batch file
     * @return list<SessionCarryover> Sessions it carries, in file order
     */
    private static function readBatch(string $path): array
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
        }

        return $carried;
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
