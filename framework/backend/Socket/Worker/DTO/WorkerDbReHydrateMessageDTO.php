<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\ReHydrateRound;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbReHydrateMessageDTO - whole-database re-hydration frame (HIL-479).
 *
 * Travels both ways over the worker link: an agent that replaced the database under the live
 * node emits it to its own daemon, and the daemon fans the same frame back out to every worker.
 * Unlike the {@see WorkerDbSyncClearedMessageDTO} family it carries no state at all - the
 * event is "the database underneath you is a different one now", which names no collection and
 * no row, and {@see DbSyncApplicator::applyReHydrate()} re-reads everything.
 *
 * It does name the agent that announced the swap (HIL-436). That agent now waits for a
 * {@see ReHydrateRound} to close and has to be findable when the verdict is ready; on the way
 * back out to the other workers the id is simply carried along, since the answer belongs to the
 * initiator's node and not to whoever re-reads.
 */
class WorkerDbReHydrateMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_REHYDRATE;

    /**
     * @param ?string $agentId Agent that announced the swap and awaits the verdict, null when nobody does
     */
    public function __construct(
        public readonly ?string $agentId,
    ) {
    }

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
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (agentId).
     * @return static DTO instance.
     */
    public static function fromArray(array $data): static
    {
        $agentId = $data[AgentConstants::FIELD_AGENT_ID] ?? null;

        return new static(
            agentId: $agentId === null ? null : (string)$agentId,
        );
    }
}
