<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\AgentConstants;

/**
 * Parsed agent identity: an agent type with an optional multi-instance index.
 */
final class AgentId
{
    /**
     * @param string $type Agent type
     * @param ?string $index Agent index, or null for singleton agents
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $index = null,
    ) {
    }

    /**
     * Parses an agent ID string ("type" or "type:index") into its parts.
     *
     * A trailing separator names no index. {@see AbstractAgent::getId()} spells a
     * singleton agent as the bare type, so "type:" is that same identity written
     * with a stray separator; read as an empty index it would become a second
     * spelling of "no index", and the two would then key apart everything that
     * keys by identity — the agent analytics session among them.
     *
     * @param string $agentId Agent ID
     * @return self Parsed identity
     */
    public static function fromId(string $agentId): self
    {
        $parts = explode(AgentConstants::ID_SEPARATOR, $agentId, AgentConstants::ID_MAX_PARTS);
        $index = $parts[1] ?? null;

        return new self($parts[0], $index === '' ? null : $index);
    }
}
