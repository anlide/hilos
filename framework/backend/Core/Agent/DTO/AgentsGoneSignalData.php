<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Master → the agent that declared {@see HilosSignalConstants::HILOS_AGENTS_GONE}: these agents
 * are gone, and whatever they were carrying will not be answered by them (HIL-1044).
 *
 * The master knows when an agent's process died, when its start failed and when the leader
 * placed it nowhere ({@see DaemonManager}); the agent that was answering somebody on their behalf
 * does not, and before this frame the only way it found out was a clock. The frame carries the
 * ids and the reason and nothing about the work: which of its waits went through those agents is
 * the receiver's own knowledge.
 */
final class AgentsGoneSignalData extends BaseDTO implements SignalDataInterface
{
    /** The worker the agents were running in died. */
    public const string REASON_WORKER_DIED = 'worker_died';

    /** The agent's start failed. */
    public const string REASON_START_FAILED = 'start_failed';

    /** The leader placed the agent on no node. */
    public const string REASON_NOT_PLACED = 'not_placed';

    public const string agentIds = 'agentIds';
    public const string reason = 'reason';

    /**
     * @param list<string> $agentIds Ids of the agents that are gone
     * @param string $reason One of the REASON_* constants
     */
    public function __construct(
        public readonly array $agentIds,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array{agentIds: list<string>, reason: string} DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::agentIds => $this->agentIds,
            self::reason => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no agents or no reason
     */
    public static function fromArray(array $data): static
    {
        return new static(
            agentIds: self::optionalStringList($data, self::agentIds)
                ?? throw new InvalidFormatException('Payload carries no string list under key ' . self::agentIds),
            reason: self::requireString($data, self::reason),
        );
    }
}
