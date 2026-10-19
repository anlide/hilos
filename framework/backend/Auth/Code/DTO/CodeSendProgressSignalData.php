<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Session\DTO\SessionToastsSignalData;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\View\Item\HilosCodeSendAttempt;

/**
 * Sessions library → every tab of one browser session: this is how the code is travelling
 * (HIL-826).
 *
 * The frame carries the WHOLE line rather than the change, the shape
 * {@see SessionToastsSignalData} established: a reload, a second tab and an ordinary step are
 * then one and the same sentence, and a tab that has just come back needs nothing but this
 * frame to be right.
 *
 * A frame with a null {@see state} is legal and means the line goes away - there is no send to
 * describe. It is sent on every handshake INCLUDING then, because silence and "you are owed
 * nothing" must not look the same to a browser: a tab that reconnects into a session whose
 * send has been let go would otherwise sit under a line that nothing will ever take off.
 *
 * {@see detail} carries the provider's own sentence and is deliberately NOT a stable key: the
 * whole point of the line's last state is that a person is told "mailbox unavailable (550)"
 * rather than "something went wrong", and no key of ours can hold words we did not write. It
 * rides on a refusal and nowhere else.
 */
final class CodeSendProgressSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param ?string $state One of the send states, or null when there is no line
     * @param ?string $channel Channel the code travels over, or null when there is no line
     * @param ?string $purpose Profile operation, or null for a guest line
     * @param ?string $detail Provider's sentence, on a refusal and nowhere else
     * @param ?string $ticket Send the line follows, which is how a tab tells its own send from a replayed one
     * @param ?string $reason How the code agent's send ended, on its closing step alone (HIL-1044)
     * @param ?int $resendAt Server moment a send is allowed again, in epoch ms, or null
     * @param ?int $expiresAt Server moment the live code dies, in epoch ms, or null
     * @param ?string $message Safe refusal sentence, separate from the provider detail
     */
    public function __construct(
        public readonly ?string $state = null,
        public readonly ?string $channel = null,
        public readonly ?string $purpose = null,
        public readonly ?string $detail = null,
        public readonly ?string $ticket = null,
        public readonly ?string $reason = null,
        public readonly ?int $resendAt = null,
        public readonly ?int $expiresAt = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * Builds the frame for how one session's code is travelling, which may be "not at all".
     *
     * The one place a stored line is turned into a shown one, which is why the null row is
     * handled here rather than at each call site: a session whose row has just been taken away
     * is being shown no line, and that has to reach the tabs like any other state.
     *
     * @param ?HilosCodeSendAttempt $attempt Session's line, or null when it has none
     * @return self Frame carrying the whole line
     */
    public static function fromAttempt(?HilosCodeSendAttempt $attempt): self
    {
        if ($attempt === null) {
            return new self();
        }

        return new self(
            state: $attempt->state,
            channel: $attempt->channel,
            purpose: $attempt->purpose,
            detail: $attempt->detail,
            ticket: $attempt->ticket,
            reason: $attempt->reason,
            resendAt: $attempt->resendAt,
            expiresAt: $attempt->expiresAt,
            message: match ($attempt->reason) {
                StateHilosCodeSendAttempt::REASON_CHANNEL_UNAVAILABLE => 'That number cannot be reached this way.',
                StateHilosCodeSendAttempt::REASON_CAP_REACHED =>
                    'Too many codes have been sent to this number. Please try again later.',
                StateHilosCodeSendAttempt::REASON_CONSENT_REVISED => AuthMessages::CONSENT_REVISED,
                StateHilosCodeSendAttempt::REASON_TERMS_UNPUBLISHED => AuthMessages::TERMS_UNPUBLISHED,
                StateHilosCodeSendAttempt::REASON_CODE_SENT,
                StateHilosCodeSendAttempt::REASON_RATE_LIMITED,
                StateHilosCodeSendAttempt::REASON_CONSENT_REQUIRED => null,
                default => $attempt->state === StateHilosCodeSendAttempt::STATE_FAILED
                    ? 'Could not send the code. Please try again.'
                    : null,
            },
        );
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'detail' => $this->detail,
            'ticket' => $this->ticket,
            'reason' => $this->reason,
            'resendAt' => $this->resendAt,
            'expiresAt' => $this->expiresAt,
            'message' => $this->message,
        ];
    }

    /**
     * Rebuilds the line one session is being shown.
     *
     * The nullable message is required, including on an empty frame. The other fields remain
     * optional: a payload with no state takes the line away.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When message is absent or a field has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            state: self::optionalString($data, 'state'),
            channel: self::optionalString($data, 'channel'),
            purpose: self::optionalString($data, 'purpose'),
            detail: self::optionalString($data, 'detail'),
            ticket: self::optionalString($data, 'ticket'),
            reason: self::optionalString($data, 'reason'),
            resendAt: self::optionalInt($data, 'resendAt'),
            expiresAt: self::optionalInt($data, 'expiresAt'),
            message: self::requireNullableString($data, 'message'),
        );
    }
}
