<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Runtime\State\Item\HilosOAuthTrip;

/**
 * OAuthResumeActionDTO - payload for a tab presenting the key of its provider sign-in (HIL-1044).
 *
 * Sent after a reconnect while the exchange is still running: the outcome is owed to the
 * connection that sent the callback, the reconnect replaced it, and the key the tab minted is
 * what proves the new connection is the same tab. It is the key in the clear - the one moment it
 * travels after the callback - and it is checked against the cookie of the connection presenting
 * it, so a key without the session it was minted under opens nothing.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS: the trip it presents is the
 * holder's row.
 */
final class OAuthResumeActionDTO extends ActionPayloadDTO
{
    public const string tripKey = 'tripKey';

    /**
     * @param string $tripKey Key the tab minted for its trip
     */
    public function __construct(
        public readonly string $tripKey,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_OAUTH_RESUME;
    }

    /**
     * Create from array, unwrapping the optional FIELD_DATA envelope.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Resume DTO instance
     * @throws InvalidFormatException When the payload carries no key, or one no tab mints
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $tripKey = self::requireString($inner, self::tripKey);
        HilosOAuthTrip::ensureValidKey($tripKey);

        return new static(tripKey: $tripKey);
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, string> Payload with the trip key
     */
    public function toArray(): array
    {
        return [
            self::tripKey => $this->tripKey,
        ];
    }
}
