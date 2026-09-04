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
 *
 * The client also names the trip the start belongs to (HIL-707); the handler echoes
 * it back on that signal so the browser can drop an answer to a trip it abandoned.
 */
final class LinkOAuthStartActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates OAuth link-start action DTO.
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
            tripId: self::requireString($data, 'tripId'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{provider: string, tripId: string} OAuth link-start payload
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'tripId' => $this->tripId,
        ];
    }
}
