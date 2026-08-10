<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: the marker is spelled correctly but names nothing
 * after the colon, so it classifies no place and is reported with a text of its
 * own rather than silently accepted.
 */
final class PayloadMarkerWithoutReason
{
    /**
     * @param int $httpCode Status the frame carries
     */
    private function __construct(private readonly int $httpCode)
    {
    }

    /**
     * @param array<string, mixed> $data Payload to read
     * @return self Sample built from the payload
     */
    public static function fromArray(array $data): self
    {
        // external-boundary:
        return new self($data['httpCode'] ?? 0);
    }
}
