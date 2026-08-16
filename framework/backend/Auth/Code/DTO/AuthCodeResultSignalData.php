<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * AuthCodeResultSignalData - what became of a phone code request (HIL-492).
 *
 * Delivered WS_USER to the requesting connection's accept key, the shape
 * {@see OAuthResultSignalData} established for an async outcome owed to a guest: no
 * account exists yet, so there is no user to fan out to and no session update to ride.
 *
 * SUCCESS travels here too, unlike the OAuth case, and that is the point of the
 * signal: the code screen must open only once a code really went out, and it must
 * name the channel it went over. Opening it on the click would be a promise the
 * transport has not made yet - and after a channel turns out unreachable, a screen
 * saying "enter the code we sent via Telegram" would be a lie about a message that
 * does not exist.
 *
 * {@see reason} is a stable, non-sensitive code; why a transport failed stays in the
 * agent log. {@see resendInSeconds} is filled on the arms where waiting is the
 * answer - a fresh send's cooldown, or what is left of one - and null where waiting
 * changes nothing.
 */
final class AuthCodeResultSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /** A code went out over {@see channel}: the surface opens the code screen and names it. */
    public const string REASON_CODE_SENT = 'code_sent';

    /**
     * The channel cannot reach this identifier: nothing was minted and no cooldown was
     * spent, so the surface dims this channel and the person may pick another one.
     */
    public const string REASON_CHANNEL_UNAVAILABLE = 'code_channel_unavailable';

    /** The cooldown between sends has not run out: {@see resendInSeconds} says how long is left. */
    public const string REASON_RATE_LIMITED = 'code_rate_limited';

    /** The per-window cap refused the send: waiting a little changes nothing, so no seconds ride along. */
    public const string REASON_CAP_REACHED = 'code_cap_reached';

    /** A code was minted but the transport refused it: the surface offers a resend. */
    public const string REASON_SEND_FAILED = 'code_send_failed';

    /**
     * @param string $acceptKey Requesting connection accept key the signal targets
     * @param string $channel Code channel the request named
     * @param string $reason Stable, non-sensitive outcome code (see self::REASON_*)
     * @param ?int $resendInSeconds Seconds until a send is allowed again, or null when waiting is not the answer
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $channel,
        public readonly string $reason,
        public readonly ?int $resendInSeconds = null,
    ) {
    }

    /**
     * @return string Accept key the outcome is delivered to
     */
    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'channel' => $this->channel,
            'reason' => $this->reason,
            'resendInSeconds' => $this->resendInSeconds,
        ];
    }

    /**
     * Rebuilds the outcome the code agent minted.
     *
     * {@see reason} is required: it is the whole meaning of the signal, and a missing
     * one has no safe default - read as success it opens a code screen for a code that
     * never went out, read as failure it hides one that did.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key, channel or reason
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            channel: self::requireString($data, 'channel'),
            reason: self::requireString($data, 'reason'),
            resendInSeconds: self::optionalInt($data, 'resendInSeconds'),
        );
    }
}
