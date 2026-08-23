<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * RequestMagicLinkActionDTO - DTO for an email magic-link request payload (HIL-283).
 *
 * Public (anonymous-reachable) submit that asks for a passwordless sign-in link to
 * be sent to an email. The email is trimmed here and lowercased by the handler
 * before the token is issued; the response is always the same generic success, so
 * this payload never reveals whether the address has an account.
 */
final class RequestMagicLinkActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a magic-link request DTO.
     *
     * @param string $email Submitted account email (trimmed)
     */
    public function __construct(
        public readonly string $email,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Request DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string} Request payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
        ];
    }
}
