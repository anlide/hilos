<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: every stub minted inside a payload reader must be
 * reported by PAYLOAD-SENTINEL. All three literals are seeded, all three
 * spellings that mint them, and both readers the rule judges — a reader is
 * named by its name and not by the file it sits in.
 */
final class PayloadSentinelSamples
{
    /**
     * @param string $page Page the frame names
     * @param int $httpCode Status the frame carries
     * @param float $weight Weight the frame carries
     */
    private function __construct(
        private readonly string $page,
        private readonly int $httpCode,
        private readonly float $weight,
    ) {
    }

    /**
     * @param array<string, mixed> $data Payload to read
     * @return self Sample built from the payload
     */
    public static function fromArray(array $data): self
    {
        $page = $data['page'] ?? '';
        $httpCode = $data['httpCode'] ?? 0;
        $weight = $data['weight'] ?? 0.0;

        return new self($page, $httpCode, $weight);
    }

    /**
     * @param string $json Payload as it arrived
     * @return self Sample built from the payload
     */
    public static function fromJson(string $json): self
    {
        $data = (array)json_decode($json, true);
        $page = isset($data['page']) ? $data['page'] : "";
        $httpCode = match (true) {
            isset($data['httpCode']) => $data['httpCode'],
            default => 0,
        };

        return new self($page, $httpCode, 1.5);
    }
}
