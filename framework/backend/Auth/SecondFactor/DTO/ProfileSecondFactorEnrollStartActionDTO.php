<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileSecondFactorEnrollStartActionDTO - DTO for starting to connect an authenticator app from the profile (HIL-494).
 *
 * The first app needs no proof - there is nothing to prove with. Every further one starts
 * with a code from an app already connected or a backup code: otherwise whoever took over a
 * live session would add an app of their own.
 */
final class ProfileSecondFactorEnrollStartActionDTO extends ActionPayloadDTO
{
    /**
     * @param ?string $proofCode Code proving the factor already connected, or null for the first app
     * @param bool $proofBackup Whether the proof is a backup code
     */
    public function __construct(
        public readonly ?string $proofCode,
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
        return HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START;
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
            proofCode: self::optionalString($data, 'proofCode'),
            proofBackup: self::optionalBool($data, 'proofBackup') === true,
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{proofCode: ?string, proofBackup: bool} Payload
     */
    public function toArray(): array
    {
        return [
            'proofCode' => $this->proofCode,
            'proofBackup' => $this->proofBackup,
        ];
    }
}
