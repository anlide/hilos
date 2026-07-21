<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * LinkOAuthAfterReauthActionDTO - DTO for the OAuth account-link action payload (HIL-282).
 *
 * Authenticated (see AUTH_ACTIONS) submit replayed by the surface after an
 * email-collision re-authentication: it carries the signed link token minted by
 * the collision branch. The handler verifies the token, asserts its email belongs
 * to the now-authenticated user, and binds the OAuth identity; the token is the
 * only field, everything else is resolved from it and the session.
 */
final class LinkOAuthAfterReauthActionDTO extends ChatActionPayloadDTO
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
        return ChatSignalConstants::LINK_OAUTH_AFTER_REAUTH;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static OAuth link DTO instance
     */
    public static function fromArray(array $data): static
    {
        $token = $data['token'] ?? null;

        return new static(
            token: is_string($token) ? $token : '',
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
