<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Server → client: current outbound message moderation state (null = none).
 */
final class OutboundModerationStateUpdateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param ?array<string, mixed> $state Outbound moderation state payload
     */
    public function __construct(
        public readonly ?array $state,
    ) {
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return ['outboundModerationState' => $this->state];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $state = $data['outboundModerationState'] ?? null;

        return new static(state: is_array($state) ? $state : null);
    }
}
