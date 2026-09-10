<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * CompleteRegistrationActionDTO - DTO for saving the first password of a registration (HIL-825).
 *
 * Public (anonymous-reachable) submit that carries the password and nothing else, and
 * the submit that creates the account: the address, its identity and this password are
 * written together when it arrives. The address is deliberately absent, exactly as in
 * {@see CompletePasswordResetActionDTO} - it is read off the hold this browser has
 * already proved with a code, so a payload cannot name an address whose inbox somebody
 * else answered. The password is passed through verbatim; every character in it is
 * significant.
 */
final class CompleteRegistrationActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a registration completion DTO.
     *
     * @param string $password Submitted plaintext password the account will get
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
        return HilosSignalConstants::HILOS_COMPLETE_REGISTRATION;
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
