<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * ConfirmPasswordResetActionDTO - DTO for submitting a password-reset code.
 *
 * Public (anonymous-reachable) submit that carries the emailed code and nothing
 * else. The new password left this payload with HIL-416: recovery is two screens,
 * and the one that saves the password has an action of its own
 * ({@see CompletePasswordResetActionDTO}) - a code and a password in one submit
 * would mean the person types the password before anything has been proven, and
 * that the code must be spent before it can be checked.
 *
 * The email is trimmed here and lowercased by the handler; the code is passed
 * through verbatim so leading/trailing characters stay significant.
 */
final class ConfirmPasswordResetActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a password-reset confirmation DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $code Submitted verification code
     */
    public function __construct(
        public readonly string $email,
        public readonly string $code,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
            code: trim(self::requireString($data, 'code')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, code: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'code' => $this->code,
        ];
    }
}
