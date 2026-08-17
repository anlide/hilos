<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * ConfirmRegisterActionDTO - DTO for the code that creates a reserved account.
 *
 * Public (anonymous-reachable) confirm submit (HIL-415): the code the person read
 * in their inbox, plus the address it was sent to. The address travels from the
 * client for the same reason it does on the SMS path - the session is anonymous
 * until this very action succeeds, so there is nothing on the server to resolve it
 * from. Brute force is held off by the challenge's own attempt ceiling and the
 * anti-abuse limiters (HIL-420), not by hiding the address from a payload the same
 * person just typed.
 *
 * On success the reserved account is created, its email identity is verified, and
 * the session is signed in.
 */
final class ConfirmRegisterActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a registration-confirm DTO.
     *
     * @param string $email Address whose pending registration is being confirmed (trimmed)
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
        return HilosSignalConstants::HILOS_CONFIRM_REGISTER;
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
