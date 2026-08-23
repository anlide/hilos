<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * RegisterActionDTO - DTO for the email+password registration action payload.
 *
 * Public (anonymous-reachable) register submit. The email is trimmed here and
 * lowercased by the handler before the reservation write; the password is passed
 * through verbatim so leading and trailing characters stay significant.
 *
 * There is no password confirmation field: the redesigned surface (HIL-412) has one
 * password input, and a second one that has to match it is a check the frontend
 * could never make meaningful on a field the person cannot see twice. The mistake
 * it was meant to catch is answered by recovery, which the surface now offers on
 * the same screen.
 */
final class RegisterActionDTO extends ActionPayloadDTO
{
    /**
     * Creates register action DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $password Submitted plaintext password
     */
    public function __construct(
        public readonly string $email,
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
        return HilosSignalConstants::HILOS_REGISTER;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Register DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
            password: self::requireString($data, 'password'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, password: string} Register payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
