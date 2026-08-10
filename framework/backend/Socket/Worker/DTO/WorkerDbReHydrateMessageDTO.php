<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Database\DbSyncApplicator;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbReHydrateMessageDTO - whole-database re-hydration frame (HIL-479).
 *
 * Travels both ways over the worker link: an agent that replaced the database under the live
 * node emits it to its own daemon, and the daemon fans the same frame back out to every worker.
 * Unlike the {@see WorkerDbSyncClearedMessageDTO} family it carries no payload at all - the
 * event is "the database underneath you is a different one now", which names no collection and
 * no row, and {@see DbSyncApplicator::applyReHydrate()} re-reads everything.
 */
class WorkerDbReHydrateMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_REHYDRATE;

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
     * The frame has no payload, so the source array is read for nothing beyond its type,
     * which the factory has already matched.
     *
     * @param array<string, mixed> $data Source data (type only).
     * @return static DTO instance.
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
