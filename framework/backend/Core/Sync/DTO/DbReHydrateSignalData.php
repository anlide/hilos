<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\ReHydrateRound;

/**
 * DbReHydrateSignalData - payload of the whole-database re-hydrate signal (HIL-479).
 *
 * The database underneath the running node was replaced, so every DB-backed collection has to
 * be re-read ({@see DbSyncApplicator::applyReHydrate()}). The fact names no collection and no
 * row - which is why, unlike its siblings in this namespace, it does not implement
 * {@see DbSyncSignalDataInterface}: there is no key to dedupe or route on.
 *
 * It does name its sender (HIL-436). The announcement is no longer fire-and-forget: the daemon
 * opens a {@see ReHydrateRound} over it and has to send the verdict back to exactly the agent
 * that is waiting for it, which mirrors how the protected-mode payloads in this codebase carry
 * their initiator.
 */
final class DbReHydrateSignalData extends BaseDTO implements SignalDataInterface
{
    /** @var string Field key carrying the node that announced the swap to this one */
    public const string FIELD_REPLY_TO_NODE_ID = 'replyToNodeId';

    /**
     * Exactly one of the two is set: a swap is announced either by an agent on this node, or by
     * another node over the mesh, and the barrier reports back to whichever it was.
     *
     * @param ?string $agentId Agent that replaced the database here, null when another node announced it
     * @param ?string $replyToNodeId Node that announced the swap to this one, null when this node did
     */
    public function __construct(
        public readonly ?string $agentId,
        public readonly ?string $replyToNodeId = null,
    ) {
    }

    /**
     * @return array<string, mixed> Announcing agent id and announcing node, whichever applies
     */
    public function toArray(): array
    {
        return [
            AgentConstants::FIELD_AGENT_ID => $this->agentId,
            self::FIELD_REPLY_TO_NODE_ID => $this->replyToNodeId,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data (agentId, replyToNodeId; both optional)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $agentId = $data[AgentConstants::FIELD_AGENT_ID] ?? null;
        $replyToNodeId = $data[self::FIELD_REPLY_TO_NODE_ID] ?? null;

        return new static(
            agentId: $agentId === null ? null : (string)$agentId,
            replyToNodeId: $replyToNodeId === null ? null : (string)$replyToNodeId,
        );
    }
}
