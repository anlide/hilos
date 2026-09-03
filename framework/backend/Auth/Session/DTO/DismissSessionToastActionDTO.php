<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DismissSessionToastActionDTO - payload for closing a toast of the session (HIL-768).
 *
 * The cross on a card the SERVER raised. Unlike the ack dismissal it stands beside, it has to
 * name WHICH card: a session can be shown several at once, and the stack is a list rather than
 * a single mark.
 *
 * The key is the server's own name for the card and reaches the browser on the frame; there is
 * nothing a client could invent with it, because a key belonging to another session is simply
 * not in this session's row.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS: the stack it edits is the
 * session's, and the cards are drawn over whatever page the person is on.
 */
final class DismissSessionToastActionDTO extends ActionPayloadDTO
{
    public const string key = 'key';

    /**
     * @param string $key Server-minted name of the card being closed
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
        return HilosSignalConstants::HILOS_TOAST_DISMISS;
    }

    /**
     * Create from array, unwrapping the optional FIELD_DATA envelope.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Dismiss DTO instance
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
