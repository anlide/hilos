<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;

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
        return HilosSignalConstants::HILOS_OAUTH_START;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static OAuth start DTO instance
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
     * @return array{provider: string} OAuth start payload
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
        ];
    }
}
