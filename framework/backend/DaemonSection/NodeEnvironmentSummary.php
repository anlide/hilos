<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** Counts and per-key value fingerprints, safe to carry without environment values. */
final readonly class NodeEnvironmentSummary
{
    /**
     * @param int $catalogKeys Number of declared keys
     * @param int $missingRequired Required keys without a value
     * @param int $fromExample Keys currently resolved from .env.example
     * @param int $drifted Keys whose restart value or source would differ
     * @param int $orphans Uncataloged entries in the active .env file
     * @param list<NodeEnvironmentFingerprint> $fingerprints One for each catalog key in declaration order
     */
    public function __construct(
        public int $catalogKeys,
        public int $missingRequired,
        public int $fromExample,
        public int $drifted,
        public int $orphans,
        public array $fingerprints,
    ) {
    }

    /**
     * @param list<NodeEnvironmentFingerprint> $fingerprints Replacement fingerprints
     * @return self Summary preserving the counts
     */
    public function withFingerprints(array $fingerprints): self
    {
        return new self($this->catalogKeys, $this->missingRequired, $this->fromExample, $this->drifted, $this->orphans, $fingerprints);
    }
}
