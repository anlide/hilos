<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileSecondFactorRemoveActionDTO - DTO for disconnecting an authenticator app from the profile (HIL-494).
 *
 * Proven by a code - from the very app being disconnected, if the person likes. The last app
 * takes the whole second factor with it, unless the administrator requires one.
 */
final class ProfileSecondFactorRemoveActionDTO extends ActionPayloadDTO
{
    /**
     * @param int $authenticatorId Authenticator to disconnect
     * @param string $proofCode Code from a connected app or a backup code, proving the factor
     * @param bool $proofBackup Whether the proof is a backup code
     */
    public function __construct(
        public readonly int $authenticatorId,
        public readonly string $proofCode,
        public readonly bool $proofBackup,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE;
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
            authenticatorId: self::requireInt($data, 'authenticatorId'),
            proofCode: trim(self::requireString($data, 'proofCode')),
            proofBackup: self::requireBool($data, 'proofBackup'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{authenticatorId: int, proofCode: string, proofBackup: bool} Payload
     */
    public function toArray(): array
    {
        return [
            'authenticatorId' => $this->authenticatorId,
            'proofCode' => $this->proofCode,
            'proofBackup' => $this->proofBackup,
        ];
    }
}
