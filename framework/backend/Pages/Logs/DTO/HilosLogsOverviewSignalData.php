<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * HilosLogsOverviewSignalData - Payload for Hilos logs overview page subscription (server → client).
 *
 * When archive cannot be read (permissions, I/O error, missing env), available is false and metrics are null.
 * When readable, totalRotationsAllTime is a non-negative count; lastRotationAt is null if there
 * were no rotation folders yet.
 */
final class HilosLogsOverviewSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param bool $available Whether log archive directory could be read
     * @param ?int $totalRotationsAllTime Number of rotation timestamp folders (null if unavailable)
     * @param ?string $lastRotationAt ISO 8601 datetime of the latest rotation (null if none or unavailable)
     */
    public function __construct(
        public readonly bool $available,
        public readonly ?int $totalRotationsAllTime,
        public readonly ?string $lastRotationAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'totalRotationsAllTime' => $this->totalRotationsAllTime,
            'lastRotationAt' => $this->lastRotationAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $available = $data['available'] ?? false;
        $total = $data['totalRotationsAllTime'] ?? null;
        $last = $data['lastRotationAt'] ?? null;

        return new self(
            available: is_bool($available) ? $available : false,
            totalRotationsAllTime: is_int($total) ? $total : (is_numeric($total) ? (int) $total : null),
            lastRotationAt: is_string($last) ? $last : null,
        );
    }
}
