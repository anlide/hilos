<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * OAuthCallbackActionDTO - DTO for the OAuth login callback action payload.
 *
 * Public (anonymous-reachable) callback submit: the SPA callback route hands back
 * the provider `code` and the signed `state` after the redirect. The handler
 * verifies the state synchronously (CSRF gate, no I/O) and records an in-flight op
 * for the async agent to exchange; the action ack means only "accepted, working".
 */
final class OAuthCallbackActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates OAuth callback action DTO.
     *
     * @param string $provider Provider key the callback belongs to, e.g. 'oauth:github'
     * @param string $code Authorization code returned by the provider
     * @param string $state Signed state token returned by the provider
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly string $state,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::OAUTH_CALLBACK;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static OAuth callback DTO instance
     */
    public static function fromArray(array $data): static
    {
        $provider = $data['provider'] ?? null;
        $code = $data['code'] ?? null;
        $state = $data['state'] ?? null;

        return new static(
            provider: is_string($provider) ? $provider : '',
            code: is_string($code) ? $code : '',
            state: is_string($state) ? $state : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{provider: string, code: string, state: string} OAuth callback payload
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'code' => $this->code,
            'state' => $this->state,
        ];
    }
}
