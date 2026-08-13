<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Database\ReHydrateRound;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * DbReHydrateCompleteDTO - the aggregated re-hydrate verdict, addressed to its initiator (HIL-436).
 *
 * Sent daemon -> worker once the node's {@see ReHydrateRound} settles, or once its deadline
 * passes. It names the agent that announced the swap and carries the one thing that agent is
 * waiting for: whether the whole node - and, in a cluster, the whole mesh - finished re-reading,
 * and who did not. The direct mirror of {@see ProtectedModeReadyDTO}, down to the routing;
 * unlike that frame it has a payload, because "everyone is ready" is a verdict here, not a fact
 * implied by arrival.
 *
 * @see AbstractAgent::onDbReHydrateComplete()
 */
class DbReHydrateCompleteDTO extends WorkerDTO
{
    /** @var string Whether every participant answered, and answered positively */
    public const string FIELD_COMPLETE = 'complete';

    /** @var string Human-readable lines naming the participants that failed or went quiet */
    public const string FIELD_PROBLEMS = 'problems';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_REHYDRATE_COMPLETE;

    /**
     * @param ?string $agentId Initiator agent to notify, null when the swap was announced by nobody
     * @param bool $complete Whether the barrier closed with every participant confirming
     * @param list<string> $problems One line per participant that failed or went quiet
     */
    public function __construct(
        public readonly ?string $agentId,
        public readonly bool $complete,
        public readonly array $problems = [],
    ) {
    }

    /**
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            self::FIELD_COMPLETE => $this->complete,
            self::FIELD_PROBLEMS => $this->problems,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * Reads as "not complete" when the verdict did not survive the wire, for the same fail-closed
     * reason the round itself has: an unreadable answer is not a confirmation.
     *
     * @param array<string, mixed> $data Source data (agentId, complete, problems)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $agentId = $data[AgentConstants::FIELD_AGENT_ID] ?? null;
        $problems = $data[self::FIELD_PROBLEMS] ?? [];

        return new static(
            agentId: $agentId === null ? null : (string)$agentId,
            complete: (bool)($data[self::FIELD_COMPLETE] ?? false),
            problems: is_array($problems) ? array_values(array_map(strval(...), $problems)) : [],
        );
    }
}
