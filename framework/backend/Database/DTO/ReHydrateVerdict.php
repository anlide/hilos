<?php

declare(strict_types=1);

namespace Hilos\Database\DTO;

use Hilos\Database\ReHydrateRound;

/**
 * ReHydrateVerdict - a settled {@see ReHydrateRound} plus the one address that is waiting for it.
 *
 * Internal plumbing, never on a wire: the daemon opens the same barrier in two situations and has
 * to route its answer differently. On the node where the swap was announced the answer goes to the
 * announcing agent; on a node that was told about the swap over the mesh it goes back to the node
 * that told it. Exactly one of the two addresses is set, because a node is one or the other for any
 * given swap - never both.
 */
final readonly class ReHydrateVerdict
{
    /**
     * @param ?string $agentId Agent that announced the swap on this node, or null when another node did
     * @param ?string $replyToNodeId Node that announced the swap to this one, or null when this node did
     * @param bool $complete Whether every participant answered, and answered positively
     * @param list<string> $problems One line per participant that failed or went quiet
     */
    public function __construct(
        public ?string $agentId,
        public ?string $replyToNodeId,
        public bool $complete,
        public array $problems,
    ) {
    }
}
