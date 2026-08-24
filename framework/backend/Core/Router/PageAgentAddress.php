<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Browser\Context\ConnectionIdentity;

/**
 * Where one page subscription is served: the agent type, and the instance index when the
 * page declared a per-instance route (HIL-627).
 *
 * Answers three states rather than two, for the reason {@see ConnectionIdentity} exists:
 * "the identity behind this connection has not arrived yet" and "there is no identity"
 * lead to opposite actions. A pending address is held until the answer lands; an address
 * without an index is a settled decision to serve the subscription from the fallback
 * agent the page named, and it is delivered at once.
 */
final readonly class PageAgentAddress
{
    /**
     * Private so that no caller can build the fourth, meaningless state - a pending address
     * that also names an agent. The two factories below are the whole vocabulary.
     *
     * @param bool $pending Whether the address cannot be resolved until the connection's identity arrives
     * @param string $agentType Agent type serving the subscription; empty while pending
     * @param ?string $agentIndex Instance index, or null when the subscription is served unindexed
     */
    private function __construct(
        public bool $pending,
        public string $agentType,
        public ?string $agentIndex,
    ) {
    }

    /**
     * Builds the "cannot be resolved yet" state: the connection's identity has not arrived.
     *
     * @return self Pending address, naming no agent
     */
    public static function pending(): self
    {
        return new self(true, '', null);
    }

    /**
     * Builds a settled address, indexed or not.
     *
     * @param string $agentType Agent type serving the subscription
     * @param ?string $agentIndex Instance index, or null to serve the subscription unindexed
     * @return self Settled address
     */
    public static function to(string $agentType, ?string $agentIndex): self
    {
        return new self(false, $agentType, $agentIndex);
    }
}
