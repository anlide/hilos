<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * OAuthStartActionDTO - DTO for the OAuth login start action payload.
 *
 * Public (anonymous-reachable) start submit: the client names the provider it
 * wants to authenticate with; the handler mints the authorize URL and returns it
 * on the OAUTH_AUTHORIZE signal for the browser to navigate to.
 */
final class OAuthStartActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates OAuth start action DTO.
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
        return ChatSignalConstants::OAUTH_START;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static OAuth start DTO instance
     */
    public static function fromArray(array $data): static
    {
        $provider = $data['provider'] ?? null;

        return new static(
            provider: is_string($provider) ? $provider : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{provider: string} OAuth start payload
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
        ];
    }
}
