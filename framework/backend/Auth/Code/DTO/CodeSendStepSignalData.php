<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Auth\Code\CodeSendTicket;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;

/**
 * Whoever is carrying the code → the sessions library: the send has reached this step
 * (HIL-826).
 *
 * The one door every state of the line is born through, the caller that ORDERED the code
 * included: it reports `queued` over this frame exactly as the transport reports the rest,
 * rather than drawing a first state of its own in the action reply. One path and one place
 * that fans out; two sources of truth on the first frame is what that avoids.
 *
 * {@see sessionTokenHash} and {@see channel} ride on the `queued` report and on no other,
 * because that is the one that CREATES the row. Every later step carries the ticket alone,
 * plus its state and, on a refusal, the sentence - which is what keeps the mail subsystem from
 * learning who the session is: it was handed an opaque {@see CodeSendTicket} and hands it back.
 *
 * The owner judges the ticket, not the sender ({@see AbstractSessionsLibraryAgent}): a step of
 * a send that has since been replaced finds no line to move and changes nothing.
 */
final class CodeSendStepSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @var int Longest provider sentence a person is shown; what is cut is the dialogue, not
     *     the reason, since every transport puts the reason first
     */
    private const int DETAIL_MAX_LENGTH = 160;

    /**
     * @param string $ticket Ticket of the send this step belongs to
     * @param string $state One of the five states on {@see HilosCodeSendAttempt}
     * @param ?string $sessionTokenHash Hash of the session cookie token, on the `queued` report and no other
     * @param ?string $channel Channel the code travels over, on the `queued` report and no other
     * @param ?string $detail Provider's sentence, on a refusal and nowhere else
     */
    public function __construct(
        public readonly string $ticket,
        public readonly string $state,
        public readonly ?string $sessionTokenHash = null,
        public readonly ?string $channel = null,
        public readonly ?string $detail = null,
    ) {
    }

    /**
     * Reports the step that opens the line, and is the only one that says whose it is.
     *
     * @param string $ticket Ticket minted for this send
     * @param string $sessionTokenHash Hash of the session cookie token ordering the code
     * @param string $channel Channel the code travels over
     * @return self Frame carrying the birth of the line
     */
    public static function queued(string $ticket, string $sessionTokenHash, string $channel): self
    {
        return new self(
            ticket: $ticket,
            state: HilosCodeSendAttempt::STATE_QUEUED,
            sessionTokenHash: $sessionTokenHash,
            channel: $channel,
        );
    }

    /**
     * Reports a step of a send already being watched.
     *
     * It names no session on purpose - the transport does not know one, and the line it moves
     * is found by the ticket it was given.
     *
     * @param string $ticket Ticket the transport was handed with the order
     * @param string $state One of the five states on {@see HilosCodeSendAttempt}
     * @param ?string $detail Provider's sentence, on a refusal and nowhere else
     * @return self Frame carrying one step
     */
    public static function step(string $ticket, string $state, ?string $detail = null): self
    {
        return new self(ticket: $ticket, state: $state, detail: self::sentenceOf($detail));
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'ticket' => $this->ticket,
            'state' => $this->state,
            'sessionTokenHash' => $this->sessionTokenHash,
            'channel' => $this->channel,
            'detail' => $this->detail,
        ];
    }

    /**
     * Rebuilds one reported step.
     *
     * The ticket and the state are required: a step with no ticket names no line, and one with
     * no state has nothing to say about it. The other three are the fields only some steps
     * carry.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no ticket or no state
     */
    public static function fromArray(array $data): static
    {
        return new static(
            ticket: self::requireString($data, 'ticket'),
            state: self::requireString($data, 'state'),
            sessionTokenHash: self::optionalString($data, 'sessionTokenHash'),
            channel: self::optionalString($data, 'channel'),
            detail: self::optionalString($data, 'detail'),
        );
    }

    /**
     * Cuts a transport's error down to the one sentence a person can act on.
     *
     * The first line, its whitespace collapsed, capped in length. Every transport we speak to
     * puts the reason first and the dialogue after it, so the first line is the part that
     * means something to somebody who is not us - "mailbox unavailable (550)" rather than the
     * SMTP conversation that produced it. The rest stays in the agent log, which is where a
     * developer looks and a person never does.
     *
     * Done HERE rather than at each transport so the boundary is crossed once: the sentence
     * travels to a browser, and a reporter that forgot to trim would be the whole leak.
     *
     * @param ?string $detail Transport's own error text, or null when the step is not a refusal
     * @return ?string One short sentence, or null when there was nothing to say
     */
    private static function sentenceOf(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $firstLine = strtok($detail, "\r\n");
        if ($firstLine === false) {
            return null;
        }

        // The pattern is a literal, so the replace cannot fail on it; the cast is for the
        // signature, which has to allow for one that could.
        $collapsed = trim((string)preg_replace('/\s+/u', ' ', $firstLine));
        if ($collapsed === '') {
            return null;
        }

        return mb_substr($collapsed, 0, self::DETAIL_MAX_LENGTH);
    }
}
