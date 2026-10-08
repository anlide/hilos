<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** Counts safe to carry in a node picture without its environment values. */
final readonly class NodeEnvironmentSummary
{
    /**
     * @param int $catalogKeys Number of declared keys
     * @param int $missingRequired Required keys without a value
     * @param int $fromExample Keys currently resolved from .env.example
     * @param int $drifted Keys whose restart value or source would differ
     * @param int $orphans Uncataloged entries in the active .env file
     */
    public function __construct(
        public int $catalogKeys,
        public int $missingRequired,
        public int $fromExample,
        public int $drifted,
        public int $orphans,
    ) {
    }
}
