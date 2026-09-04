<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * OAuthStartActionDTO - DTO for the OAuth login start action payload.
 *
 * Public (anonymous-reachable) start submit: the client names the provider it
 * wants to authenticate with; the handler mints the authorize URL and returns it
 * on the OAUTH_AUTHORIZE signal for the browser to navigate to.
 *
 * The client also names the trip the start belongs to (HIL-707). The server never
 * reads it — it comes back on the authorize signal untouched, so the browser can
 * tell the answer to the trip it is running from a late answer to a trip it
 * abandoned.
 */
final class OAuthStartActionDTO extends ActionPayloadDTO
{
    /**
     * Creates OAuth start action DTO.
     *
     * @param string $provider Requested provider key, e.g. 'oauth:github'
     * @param string $tripId The browser's id for this trip, echoed back on the authorize signal
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $tripId,
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
            tripId: self::requireString($data, 'tripId'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{provider: string, tripId: string} OAuth start payload
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'tripId' => $this->tripId,
        ];
    }
}
