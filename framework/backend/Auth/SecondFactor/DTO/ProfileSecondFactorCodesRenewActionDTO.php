<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileSecondFactorCodesRenewActionDTO - DTO for issuing a new set of backup codes in the profile (HIL-494).
 *
 * Proven by a code - the NEXT one of the app, since the replay guard will not take the code
 * that just opened the list - or by a backup code. The old set dies with it.
 */
final class ProfileSecondFactorCodesRenewActionDTO extends ActionPayloadDTO
{
    /**
     * @param string $proofCode Code from a connected app or a backup code, proving the factor
     * @param bool $proofBackup Whether the proof is a backup code
     */
    public function __construct(
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
        return HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW;
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
            proofCode: trim(self::requireString($data, 'proofCode')),
            proofBackup: self::requireBool($data, 'proofBackup'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{proofCode: string, proofBackup: bool} Payload
     */
    public function toArray(): array
    {
        return [
            'proofCode' => $this->proofCode,
            'proofBackup' => $this->proofBackup,
        ];
    }
}
