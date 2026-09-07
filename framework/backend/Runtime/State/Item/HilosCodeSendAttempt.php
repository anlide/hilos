<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Auth\Code\CodeSendTicket;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosCodeSendAttempts;
use Hilos\Runtime\View\Actions\Collection\HilosCodeSendAttemptsActions;

/**
 * HilosCodeSendAttempt - how the code one browser session is waiting for is travelling (HIL-826).
 *
 * The row behind the line on the code screen. Pressing the button used to move the screen and
 * say nothing further: whether the letter left, is still queued behind a stalled SMTP or was
 * refused outright, the person could not tell, because all three look like waiting for digits.
 * This row is what the transports report their steps into, and it is the only thing the screen
 * reads.
 *
 * ONE ROW PER SESSION, and the id is the hash of its cookie token - the same form the toast
 * stack, the freeze and {@see AbstractAgent::resolveInitiatorSessionTokenHash()} use, so a
 * sender that knows who asked can name the row without ever holding the token itself. Being
 * the session's rather than the socket's is what makes a reload and a second tab read the same
 * line, and another browser read nothing.
 *
 * Framework-owned runtime state mounted by the sign-in feature ({@see HilosCodeSendAttempts}),
 * written by the agent that owns the session seam and by nobody else. Runtime rather than
 * durable on purpose: the line lives as long as the code does - minutes - and a restart that
 * loses it loses a sentence about a code nobody can enter any more anyway.
 *
 * The row remembers ONE send, named by {@see self::ticket}. A report carrying any other ticket
 * is a step of an attempt this line has moved on from - a resend replaced it, or a dead
 * attempt spoke late - and it changes nothing ({@see HilosCodeSendAttemptsActions::advance()}).
 * That is the whole of the resend race, settled without comparing moments.
 */
final class HilosCodeSendAttempt extends RtState
{
    /** Runtime collection key mounted by the sign-in feature and used for RT sync. */
    public const string RT_COLLECTION = 'hilosCodeSendAttempts';

    public const string sessionTokenHash = 'sessionTokenHash';
    public const string ticket = 'ticket';
    public const string channel = 'channel';
    public const string state = 'state';
    public const string detail = 'detail';
    public const string updatedAt = 'updatedAt';

    /** The order is placed and nothing has been attempted yet. */
    public const string STATE_QUEUED = 'queued';

    /** A transport is attempting the send right now. */
    public const string STATE_SENDING = 'sending';

    /** The transport handed the code over; the digits are on their way. */
    public const string STATE_SENT = 'sent';

    /**
     * The send is over and the code did not go.
     *
     * Terminal, and that is the point: the raw-send queue retries with a growing backoff, so a
     * refusal with attempts left goes back to {@see self::STATE_QUEUED} instead of here. A line
     * that said "could not send" and then "sent" a second later would be flicker, and this is
     * the state a person acts on by pressing resend.
     */
    public const string STATE_FAILED = 'failed';

    /** The channel of a code that travels as a letter; every other value is a code channel key. */
    public const string CHANNEL_EMAIL = 'email';

    /** Hash of the session cookie token this line is addressed to; also the row id. */
    private(set) string $sessionTokenHash = '';

    /** The send this line is about, minted by {@see CodeSendTicket::mint()}. */
    private(set) string $ticket = '';

    /** {@see self::CHANNEL_EMAIL}, or the key of the code channel carrying it. */
    private(set) string $channel = '';

    /** One of the four states above. */
    private(set) string $state = '';

    /** The provider's own sentence, on {@see self::STATE_FAILED} and nowhere else. */
    private(set) ?string $detail = null;

    /** Epoch milliseconds of the last write, on the server's scale. */
    private(set) int $updatedAt = 0;

    /**
     * Opens the line for one send.
     *
     * It is born already queued rather than empty, because a row with no state is a row the
     * screen cannot read: the line exists exactly while there is a send to describe, and a
     * frame in between would put a shape on the wire that means nothing.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $ticket Ticket of the send this line follows
     * @param string $channel {@see self::CHANNEL_EMAIL} or a code channel key
     * @param int $updatedAt Epoch milliseconds of this write
     * @return static Fresh attempt row, queued
     */
    public static function create(
        string $sessionTokenHash,
        string $ticket,
        string $channel,
        int $updatedAt,
    ): static {
        $instance = new static();
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->ticket = $ticket;
        $instance->channel = $channel;
        $instance->state = self::STATE_QUEUED;
        $instance->updatedAt = $updatedAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Attempt row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the line is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->sessionTokenHash = self::requireString($row, self::sessionTokenHash);
        $instance->ticket = self::requireString($row, self::ticket);
        $instance->channel = self::requireString($row, self::channel);
        $instance->state = self::requireString($row, self::state);
        $instance->detail = self::optionalString($row, self::detail);
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * Neither the id nor the ticket is among the patchable fields. The id is the session's, and
     * a row that could be re-addressed mid-flight would show one browser's progress to another;
     * the ticket names WHICH send the line follows, and a send that has been replaced gets a
     * new row through {@see HilosCodeSendAttemptsActions::start()} rather than a rewritten one.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->channel = self::patchString($diff, self::channel, $this->channel);
        $this->state = self::patchString($diff, self::state, $this->state);
        $this->detail = self::patchOptionalString($diff, self::detail, $this->detail);
        $this->updatedAt = self::patchInt($diff, self::updatedAt, $this->updatedAt);
    }

    /**
     * @return string Runtime collection key for code send attempts
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the session token hash
     */
    public function getId(): string
    {
        return $this->sessionTokenHash;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::sessionTokenHash => $this->sessionTokenHash,
            self::ticket => $this->ticket,
            self::channel => $this->channel,
            self::state => $this->state,
            self::detail => $this->detail,
            self::updatedAt => $this->updatedAt,
        ];
    }
}
