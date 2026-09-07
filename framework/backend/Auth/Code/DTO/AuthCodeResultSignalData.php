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
 * SUCCESS travels here too, unlike the OAuth case, and it is what names the channel
 * the code really went over. What it no longer decides is WHEN the code screen opens
 * (HIL-826): the screen now opens the moment the send is ordered, as the email path
 * always has, and the send-progress line on it says queued, then sending. The old
 * rule - open only once a code really went out - was compensation for having no such
 * line, because "enter the code we sent via Telegram" was a promise the transport had
 * not made. The line makes the screen promise nothing, so the promise no longer has to
 * be timed; a channel that turns out unreachable rolls the person back to the step they
 * sent from and dims that channel, exactly as it did before.
 *
 * {@see reason} is a stable, non-sensitive code; why a transport failed stays in the
 * agent log. {@see resendAt} is filled on the arms where waiting is the answer -
 * the moment a fresh send's cooldown runs out, or the one already running does -
 * and null where waiting changes nothing. {@see expiresAt} is the other half of the
 * same question (HIL-486): the code screen counts down the life of the code it is
 * about to ask for, and only the arms that leave a live code carry it.
 */
final class AuthCodeResultSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /** A code went out over {@see channel}: the surface names it on the code screen. */
    public const string REASON_CODE_SENT = 'code_sent';

    /**
     * The channel cannot reach this identifier: nothing was minted and no cooldown was
     * spent, so the surface takes the person back to the step they sent from, dims this
     * channel, and lets them pick another one.
     */
    public const string REASON_CHANNEL_UNAVAILABLE = 'code_channel_unavailable';

    /** The cooldown between sends has not run out: {@see resendAt} says when it opens. */
    public const string REASON_RATE_LIMITED = 'code_rate_limited';

    /** The per-window cap refused the send: waiting a little changes nothing, so no moment rides along. */
    public const string REASON_CAP_REACHED = 'code_cap_reached';

    /** A code was minted but the transport refused it: the surface offers a resend. */
    public const string REASON_SEND_FAILED = 'code_send_failed';

    /**
     * @param string $acceptKey Requesting connection accept key the signal targets
     * @param string $channel Code channel the request named
     * @param string $reason Stable, non-sensitive outcome code (see self::REASON_*)
     * @param ?int $resendAt Server moment a send is allowed again, in epoch ms, or null when waiting is not the answer
     * @param ?int $expiresAt Server moment the live code dies, in epoch ms, or null when no code is live
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $channel,
        public readonly string $reason,
        public readonly ?int $resendAt = null,
        public readonly ?int $expiresAt = null,
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
            'resendAt' => $this->resendAt,
            'expiresAt' => $this->expiresAt,
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
            resendAt: self::optionalInt($data, 'resendAt'),
            expiresAt: self::optionalInt($data, 'expiresAt'),
        );
    }
}
