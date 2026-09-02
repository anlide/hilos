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
 *
 * The round number rides with the second address and only with it (HIL-694): it is the number the
 * announcing node opened *its* round under, carried here so the answer can be credited there. This
 * node's own round has its own number, which nobody outside it ever needs.
 */
final readonly class ReHydrateVerdict
{
    /**
     * @param ?string $agentId Agent that announced the swap on this node, or null when another node did
     * @param ?string $replyToNodeId Node that announced the swap to this one, or null when this node did
     * @param bool $complete Whether every participant answered, and answered positively
     * @param list<string> $problems One line per participant that failed or went quiet
     * @param ?int $replyToRound Round number of the announcing node, or null when this node announced it
     */
    public function __construct(
        public ?string $agentId,
        public ?string $replyToNodeId,
        public bool $complete,
        public array $problems,
        public ?int $replyToRound = null,
    ) {
    }
}
