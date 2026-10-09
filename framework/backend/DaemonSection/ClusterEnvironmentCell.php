<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidArgumentException;

/** One node's answer for a key, or the reason it has no answer. */
final readonly class ClusterEnvironmentCell
{
    /** @throws InvalidArgumentException When a known cell lacks a fingerprint or another cell carries one */
    public function __construct(
        public string $nodeId,
        public ClusterEnvironmentCellState $state,
        public ?NodeEnvironmentFingerprint $fingerprint,
    ) {
        if (($state === ClusterEnvironmentCellState::Known) !== ($fingerprint !== null)) {
            throw new InvalidArgumentException('Only a known environment cell carries a fingerprint');
        }
    }
}
