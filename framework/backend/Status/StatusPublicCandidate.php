<?php

declare(strict_types=1);

namespace Hilos\Status;

/** A current Checking fact, with no durable identity or publication controls. */
final readonly class StatusPublicCandidate
{
    /**
     * @param string $componentKey Catalog key
     * @param int $detectedAt Detection epoch milliseconds UTC
     */
    public function __construct(public string $componentKey, public int $detectedAt)
    {
    }

    /** @return array<string, mixed> Literal public v1 candidate */
    public function toArray(): array
    {
        return ['componentKey' => $this->componentKey, 'detectedAt' => $this->detectedAt];
    }
}
