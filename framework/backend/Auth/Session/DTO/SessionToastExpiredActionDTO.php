<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * SessionToastExpiredActionDTO - payload for "my countdown for this card has finished" (HIL-768).
 *
 * A REPORT and not a request: the tab goes on showing the card, and the server decides whether
 * it goes away, because a neighbouring window may have a cursor resting on the same stack. Each
 * tab counts its own twenty seconds - the countdown is deliberately not synchronized - so this
 * arrives once per tab per card.
 *
 * It names the card for the same reason the dismissal does: a stack holds several at a time.
 * An error card never sends it at all, having no countdown to finish.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS: the stack it answers about
 * is the session's.
 */
final class SessionToastExpiredActionDTO extends ActionPayloadDTO
{
    public const string key = 'key';

    /**
     * @param string $key Server-minted name of the card whose countdown finished
     */
    public function __construct(
        public readonly string $key,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_TOAST_EXPIRED;
    }

    /**
     * Create from array, unwrapping the optional FIELD_DATA envelope.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Expiry-report DTO instance
     * @throws InvalidFormatException When the payload names no card
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            key: self::requireString($inner, self::key),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Payload with the card's key
     */
    public function toArray(): array
    {
        return [
            self::key => $this->key,
        ];
    }
}
