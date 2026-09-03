<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * SessionToastReadingActionDTO - payload for "the stack is being read in this tab" (HIL-768).
 *
 * One action for both edges rather than a pair of names, because what the server stores is a
 * state and not an event: a tab that started reading and then went away has to be able to say
 * so, and two names would let the two halves get out of step in ways one boolean cannot.
 *
 * Reading means a cursor over the stack or the keyboard focus inside it. A hidden tab is not
 * reading: it freezes its own countdown, but nobody is looking at it, and letting it hold the
 * stack would make every toast immortal in the window actually in use.
 *
 * It names no card. A cursor rests on the stack, not on one of the cards in it.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS: the stack it holds is the
 * session's.
 */
final class SessionToastReadingActionDTO extends ActionPayloadDTO
{
    public const string reading = 'reading';

    /**
     * @param bool $reading Whether the stack is being read in the acting tab
     */
    public function __construct(
        public readonly bool $reading,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_TOAST_READING;
    }

    /**
     * Create from array, unwrapping the optional FIELD_DATA envelope.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Reading DTO instance
     * @throws InvalidFormatException When the payload does not say whether the stack is being read
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            reading: self::requireBool($inner, self::reading),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Payload with the reading flag
     */
    public function toArray(): array
    {
        return [
            self::reading => $this->reading,
        ];
    }
}
