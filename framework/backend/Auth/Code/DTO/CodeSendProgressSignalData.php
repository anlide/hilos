<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Auth\Session\DTO\SessionToastsSignalData;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
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
     * @param ?string $state One of the four states of the send, or null when there is no line
     * @param ?string $channel Channel the code travels over, or null when there is no line
     * @param ?string $detail Provider's sentence, on a refusal and nowhere else
     */
    public function __construct(
        public readonly ?string $state = null,
        public readonly ?string $channel = null,
        public readonly ?string $detail = null,
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
            detail: $attempt->detail,
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
            'detail' => $this->detail,
        ];
    }

    /**
     * Rebuilds the line one session is being shown.
     *
     * Every field is optional, and that is the empty frame rather than a lax reader: a payload
     * with no state IS the sentence that takes the line away, so refusing it would refuse the
     * commonest frame the signal carries.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field is present and is not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            state: self::optionalString($data, 'state'),
            channel: self::optionalString($data, 'channel'),
            detail: self::optionalString($data, 'detail'),
        );
    }
}
