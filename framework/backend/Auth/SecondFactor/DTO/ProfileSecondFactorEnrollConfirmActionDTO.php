<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileSecondFactorEnrollConfirmActionDTO - DTO for the first code of an app being connected from the profile (HIL-494).
 *
 * The id names the enrolment the answer to the start handed out; it is checked against the
 * person's own unfinished enrolment, never taken on trust.
 */
final class ProfileSecondFactorEnrollConfirmActionDTO extends ActionPayloadDTO
{
    /**
     * @param int $authenticatorId Authenticator the enrolment started
     * @param string $code First code from the app
     * @param string $label Name the person gives the app
     */
    public function __construct(
        public readonly int $authenticatorId,
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
        return HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM;
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
            code: trim(self::requireString($data, 'code')),
            label: trim(self::requireString($data, 'label')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{authenticatorId: int, code: string, label: string} Payload
     */
    public function toArray(): array
    {
        return [
            'authenticatorId' => $this->authenticatorId,
            'code' => $this->code,
            'label' => $this->label,
        ];
    }
}
