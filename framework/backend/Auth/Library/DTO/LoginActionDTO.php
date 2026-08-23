<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * LoginActionDTO - DTO for the email+password login action payload.
 *
 * Public (anonymous-reachable) login submit. The email is trimmed here and
 * lowercased by the handler before the identity lookup; the password is passed
 * through verbatim so leading/trailing characters stay significant.
 */
final class LoginActionDTO extends ActionPayloadDTO
{
    /**
     * Creates login action DTO.
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
        return HilosSignalConstants::HILOS_LOGIN;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Login DTO instance
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
     * @return array{email: string, password: string} Login payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
