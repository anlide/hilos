<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * LinkOAuthAfterReauthActionDTO - DTO for the OAuth account-link action payload (HIL-282).
 *
 * Authenticated (see AUTH_ACTIONS) submit replayed by the surface after an
 * email-collision re-authentication: it carries the signed link token minted by
 * the collision branch. The handler verifies the token, asserts its email belongs
 * to the now-authenticated user, and binds the OAuth identity; the token is the
 * only field, everything else is resolved from it and the session.
 */
final class LinkOAuthAfterReauthActionDTO extends ActionPayloadDTO
{
    /**
     * Creates OAuth link action DTO.
     *
     * @param string $token Signed link token returned by the collision branch
     */
    public function __construct(
        public readonly string $token,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static OAuth link DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            token: self::requireString($data, 'token'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{token: string} OAuth link payload
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
        ];
    }
}
