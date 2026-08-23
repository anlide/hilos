<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * OAuthCallbackActionDTO - DTO for the OAuth login callback action payload.
 *
 * Public (anonymous-reachable) callback submit: the SPA callback route hands back
 * the provider `code` and the signed `state` after the redirect. The handler
 * verifies the state synchronously (CSRF gate, no I/O) and records an in-flight op
 * for the async agent to exchange; the action ack means only "accepted, working".
 */
final class OAuthCallbackActionDTO extends ActionPayloadDTO
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
        return HilosSignalConstants::HILOS_OAUTH_CALLBACK;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static OAuth callback DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            provider: self::requireString($data, 'provider'),
            code: self::requireString($data, 'code'),
            state: self::requireString($data, 'state'),
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
