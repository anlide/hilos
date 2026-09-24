<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileSecondFactorCodesShowActionDTO - DTO for showing the backup codes in the profile (HIL-494).
 *
 * Proven by a code: the list is a way into the account.
 */
final class ProfileSecondFactorCodesShowActionDTO extends ActionPayloadDTO
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
        return HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW;
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
