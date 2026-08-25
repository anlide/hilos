<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Cluster\DbSyncSink;
use Hilos\Constants\WorkerConstants;
use Hilos\Database\DbSyncApplicator;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbReReadMessageDTO - stop trusting the database rows you hold (HIL-670).
 *
 * Sent by the daemon to every worker of its node when a peer link is established, because that
 * is the moment a window closes in which DB facts from other nodes could not arrive. A worker
 * cannot tell what it missed while the link was down, so it is told to distrust everything
 * rather than to repair anything: {@see DbSyncApplicator::applyReHydrate()} drops what a lazy
 * collection holds and re-reads what an eager one does. The database is the source either way.
 *
 * Carries no fields at all, and answers nothing — the two ways it differs from its neighbour
 * {@see WorkerDbReHydrateMessageDTO}, and both for the same reason. That frame means "the
 * database underneath you is a different one now", which is a restore: it names the agent
 * waiting on the verdict and its answers are counted into a barrier that holds a freeze open.
 * Here nobody is waiting. A barrier would exist only to be closed.
 *
 * One direction only, unlike that neighbour: the daemon reports the link, and no worker has a
 * reason to announce one. The cue this frame carries out to the workers reaches the daemon's own
 * collections through {@see DbSyncSink::reReadAfterLink()}, which is where it starts.
 */
class WorkerDbReReadMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_RE_READ;

    /**
     * Returns message type.
     *
     * @return string Message type identifier.
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array.
     */
    public function toArray(): array
    {
        return [
            self::TYPE => $this->getType(),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data; this frame reads none of it
     * @return static DTO instance.
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
