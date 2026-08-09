<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * ConfirmMagicLinkActionDTO - DTO for submitting an email magic-link token (HIL-283).
 *
 * Public (anonymous-reachable) submit relayed by the /auth/magic SPA route from a
 * clicked email link: it carries the target email and the delivered token. The
 * email is trimmed here and lowercased by the handler; the token is trimmed so
 * surrounding whitespace never fails an otherwise valid token.
 */
final class ConfirmMagicLinkActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a magic-link confirmation DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $token Submitted sign-in token (trimmed)
     */
    public function __construct(
        public readonly string $email,
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
        return ChatSignalConstants::CONFIRM_MAGIC_LINK;
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
            token: trim(self::requireString($data, 'token')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, token: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'token' => $this->token,
        ];
    }
}
