<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\BaseDTO;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Sender → sessions library: show this to that browser session (HIL-768).
 *
 * The one door through which a toast of the session is born. It is a signal rather than a call
 * because the stack is written by {@see AbstractSessionsLibraryAgent} alone: it is the only
 * process that knows which sockets a session has, and the only judge of when a card goes away.
 * A sender that wrote the row itself would be a second writer of a collection with one owner.
 *
 * The session is named by the HASH of its cookie token, which is the form a sender can get
 * without ever holding the token: {@see AbstractAgent::resolveInitiatorSessionTokenHash()}
 * turns the accept key of whoever asked into one. A sender with no initiator - a schedule, a
 * command line - has no session to name and simply raises nothing.
 *
 * There is no key on it. The name of a card is minted where the card is stored, so a sender
 * cannot address one that is already there, and a repeat of the same sentence is recognized by
 * being the same sentence rather than by the sender saying so.
 */
final class RaiseSessionToastSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionTokenHash Hash of the cookie token of the session being told
     * @param string $message Sentence the person reads
     * @param SessionToastSeverity $severity Which of the four kinds the card is
     * @param string $source Who is speaking, drawn above the sentence
     * @param string $destination Where clicking the card takes the person
     */
    public function __construct(
        public readonly string $sessionTokenHash,
        public readonly string $message,
        public readonly SessionToastSeverity $severity,
        public readonly string $source,
        public readonly string $destination,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'sessionTokenHash' => $this->sessionTokenHash,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'source' => $this->source,
            'destination' => $this->destination,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session, or a kind of card that does not exist
     */
    public static function fromArray(array $data): static
    {
        $severity = SessionToastSeverity::tryFrom(self::requireString($data, 'severity'));
        if ($severity === null) {
            throw new InvalidFormatException('Unknown session toast severity');
        }

        return new static(
            sessionTokenHash: self::requireString($data, 'sessionTokenHash'),
            message: self::requireString($data, 'message'),
            severity: $severity,
            source: self::requireString($data, 'source'),
            destination: self::requireString($data, 'destination'),
        );
    }
}
