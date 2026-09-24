<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * SecondFactorSetupConfirmActionDTO - DTO for the first code of an enrolment on the way in (HIL-494).
 *
 * The first code proves the app holds the secret; the name is the one the person gave the app,
 * and it is written only when the code is right.
 */
final class SecondFactorSetupConfirmActionDTO extends ActionPayloadDTO
{
    /**
     * @param string $code Code as typed (trimmed)
     * @param string $label Name of the authenticator (trimmed)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            code: trim(self::requireString($data, 'code')),
            label: trim(self::requireString($data, 'label')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{code: string, label: string} Payload
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
        ];
    }
}
