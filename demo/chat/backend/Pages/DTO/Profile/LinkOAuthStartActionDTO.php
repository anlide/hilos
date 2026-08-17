<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * LinkOAuthStartActionDTO - DTO for the profile OAuth link-start action payload (HIL-401).
 *
 * Authenticated profile submit: the signed-in client names the provider it wants to
 * link to its current account; the handler mints a link-mode authorize URL (the
 * initiator's user id is bound server-side into the signed state, never taken from
 * this payload) and returns it on the OAUTH_AUTHORIZE signal for the browser to
 * navigate to.
 */
final class LinkOAuthStartActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates OAuth link-start action DTO.
     *
     * @param string $provider Requested provider key, e.g. 'oauth:github'
     */
    public function __construct(
        public readonly string $provider,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LINK_OAUTH_START;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static OAuth link-start DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            provider: self::requireString($data, 'provider'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{provider: string} OAuth link-start payload
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
        ];
    }
}
