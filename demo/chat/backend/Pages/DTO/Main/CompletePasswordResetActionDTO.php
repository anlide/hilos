<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * CompletePasswordResetActionDTO - DTO for saving the new password of a recovery (HIL-416).
 *
 * Public (anonymous-reachable) submit that carries the password and nothing else.
 * The address is deliberately absent: it is read off the grant the accepted code
 * left on this session ({@see ConfirmPasswordResetActionDTO}), so a payload cannot
 * name an account other than the one whose mailbox was just proven. The password is
 * passed through verbatim - every character in it is significant.
 */
final class CompletePasswordResetActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a password-reset completion DTO.
     *
     * @param string $password Submitted new plaintext password
     */
    public function __construct(
        public readonly string $password,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Complete DTO instance
     * @throws InvalidFormatException When the password is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            password: self::requireString($data, 'password'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{password: string} Complete payload
     */
    public function toArray(): array
    {
        return [
            'password' => $this->password,
        ];
    }
}
