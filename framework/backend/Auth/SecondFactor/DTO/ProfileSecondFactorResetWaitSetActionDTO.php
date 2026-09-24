<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileSecondFactorResetWaitSetActionDTO - DTO for choosing the removal wait in the profile (HIL-494).
 *
 * Needs no code: the defence here is the delay itself - a longer wait applies at once, a
 * shorter one only after the wait in force runs out.
 */
final class ProfileSecondFactorResetWaitSetActionDTO extends ActionPayloadDTO
{
    /**
     * @param int $days Wait the person asks for, in days
     */
    public function __construct(
        public readonly int $days,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            days: self::requireInt($data, 'days'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{days: int} Payload
     */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
        ];
    }
}
