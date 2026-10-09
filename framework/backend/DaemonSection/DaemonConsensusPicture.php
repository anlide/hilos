<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Core\Exception\InvalidFormatException;

/** One master's last known view of its election and static quorum. */
final readonly class DaemonConsensusPicture
{
    /**
     * @param ConsensusRole $role Local election role
     * @param int $term Local election term
     * @param ?string $leaderId Leader this node recognises
     * @param int $onlineMasters Masters seen on the last tick
     * @param int $masters Static master-set size
     * @param int $quorumSize Majority threshold
     * @throws InvalidFormatException When the term, leader, or quorum counts are invalid
     */
    public function __construct(
        public ConsensusRole $role,
        public int $term,
        public ?string $leaderId,
        public int $onlineMasters,
        public int $masters,
        public int $quorumSize,
    ) {
        if ($term < 0 || $leaderId === '' || $onlineMasters < 0 || $masters < 1 || $quorumSize < 1
            || $onlineMasters > $masters || $quorumSize > $masters) {
            throw new InvalidFormatException('Daemon consensus picture carries an invalid term, leader, or quorum');
        }
    }
}
