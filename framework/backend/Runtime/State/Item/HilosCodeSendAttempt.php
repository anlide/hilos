<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Auth\Code\CodeSendTicket;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosCodeSendAttemptRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosCodeSendAttempts;
use Hilos\Runtime\View\Actions\Collection\HilosCodeSendAttemptsActions;
use LogicException;

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
 * line, and another browser read nothing. The purpose distinguishes a guest send from the
 * profile operation whose window ordered it.
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
    public const string purpose = 'purpose';
    public const string state = 'state';
    public const string detail = 'detail';
    public const string reason = 'reason';
    public const string resendAt = 'resendAt';
    public const string expiresAt = 'expiresAt';
    public const string updatedAt = 'updatedAt';

    /** A code went out over the channel: the surface names it on the code screen. */
    public const string REASON_CODE_SENT = 'code_sent';

    /**
     * The channel cannot reach this identifier: nothing was minted and no cooldown was
     * spent, so the surface takes the person back to the step they sent from, dims this
     * channel, and lets them pick another one.
     */
    public const string REASON_CHANNEL_UNAVAILABLE = 'code_channel_unavailable';

    /** The cooldown between sends has not run out: {@see self::$resendAt} says when it opens. */
    public const string REASON_RATE_LIMITED = 'code_rate_limited';

    /** The per-window cap refused the send: waiting a little changes nothing, so no moment rides along. */
    public const string REASON_CAP_REACHED = 'code_cap_reached';

    /** A code was minted but the transport refused it: the surface offers a resend. */
    public const string REASON_SEND_FAILED = 'code_send_failed';

    public const string REASON_CONSENT_REQUIRED = 'consent_required';
    public const string REASON_CONSENT_REVISED = 'consent_revised';
    public const string REASON_TERMS_UNPUBLISHED = 'terms_unpublished';

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

    /**
     * The send is over and the letter was written down instead of mailed (HIL-827).
     *
     * A SUCCESS, and terminal like {@see self::STATE_SENT}: the code is minted and live, the
     * resend countdown runs, and the person reads the digits out of the artifact the way the
     * mode's documentation says. It is a separate state rather than a shade of `sent` because
     * an installation that deliberately mails nobody would otherwise leave a person waiting
     * for a message that is not coming - which is the whole reason this reading exists.
     */
    public const string STATE_NOT_SENT = 'not_sent';

    /** A profile send is held by the cooldown and no earlier code remains live. */
    public const string STATE_HELD = 'held';

    /** The channel of a code that travels as a letter; every other value is a code channel key. */
    public const string CHANNEL_EMAIL = 'email';

    /** The identity confirmation step, shared by protected operations. */
    public const string PURPOSE_STEP_UP = 'step_up';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::sessionTokenHash => 'text',
        self::ticket => 'text',
        self::channel => 'text',
        self::purpose => '?text',
        self::state => 'text',
        self::detail => '?text',
        self::reason => '?text',
        self::resendAt => '?int',
        self::expiresAt => '?int',
        self::updatedAt => 'int',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::sessionTokenHash => '',
        self::ticket => '',
        self::channel => '',
        self::purpose => null,
        self::state => '',
        self::detail => null,
        self::reason => null,
        self::resendAt => null,
        self::expiresAt => null,
        self::updatedAt => 0,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::channel => true,
        self::purpose => true,
        self::state => true,
        self::detail => true,
        self::reason => true,
        self::resendAt => true,
        self::expiresAt => true,
        self::updatedAt => true,
    ];

    /** Hash of the session cookie token this line is addressed to; also the row id. */
    private(set) string $sessionTokenHash {
        get => isset($this->rowValues()[self::sessionTokenHash])
            ? $this->rowValues()[self::sessionTokenHash]->toNative()
            : self::FIELD_DEFAULTS[self::sessionTokenHash];
        set {
            $this->setRowValue(self::sessionTokenHash, RtRowValues::fromNative($value));
        }
    }

    /** The send this line is about, minted by {@see CodeSendTicket::mint()}. */
    private(set) string $ticket {
        get => isset($this->rowValues()[self::ticket]) ? $this->rowValues()[self::ticket]->toNative() : self::FIELD_DEFAULTS[self::ticket];
        set {
            $this->setRowValue(self::ticket, RtRowValues::fromNative($value));
        }
    }

    /** {@see self::CHANNEL_EMAIL}, or the key of the code channel carrying it. */
    private(set) string $channel {
        get => isset($this->rowValues()[self::channel]) ? $this->rowValues()[self::channel]->toNative() : self::FIELD_DEFAULTS[self::channel];
        set {
            $this->setRowValue(self::channel, RtRowValues::fromNative($value));
        }
    }

    /** Profile operation owning the line, or null for a guest send. */
    private(set) ?string $purpose {
        get => ($this->rowValues()[self::purpose] ?? null)?->toNative();
        set {
            $this->setRowValue(self::purpose, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** One of the six send states above. */
    private(set) string $state {
        get => isset($this->rowValues()[self::state]) ? $this->rowValues()[self::state]->toNative() : self::FIELD_DEFAULTS[self::state];
        set {
            $this->setRowValue(self::state, RtRowValues::fromNative($value));
        }
    }

    /** The provider's own sentence, on {@see self::STATE_FAILED} and nowhere else. */
    private(set) ?string $detail {
        get => ($this->rowValues()[self::detail] ?? null)?->toNative();
        set {
            $this->setRowValue(self::detail, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /**
     * How the code agent's send ended - one of the REASON_* constants - on its closing step and
     * nowhere else (HIL-1044).
     *
     * The outcome rides the line rather than a signal of its own to the connection that asked:
     * the line is replayed on every handshake, so a tab that lost its connection mid-send reads
     * the ending when it is back. Null on every step before the last and on every send carried
     * by a transport other than the code agent.
     */
    private(set) ?string $reason {
        get => ($this->rowValues()[self::reason] ?? null)?->toNative();
        set {
            $this->setRowValue(self::reason, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Server moment a send is allowed again, in epoch ms, on the arms where waiting is the answer. */
    private(set) ?int $resendAt {
        get => $this->rowValues()[self::resendAt] ?? self::FIELD_DEFAULTS[self::resendAt];
        set {
            $this->setRowValue(self::resendAt, $value);
        }
    }

    /** Server moment the live code dies, in epoch ms, on the arms that leave a live code. */
    private(set) ?int $expiresAt {
        get => $this->rowValues()[self::expiresAt] ?? self::FIELD_DEFAULTS[self::expiresAt];
        set {
            $this->setRowValue(self::expiresAt, $value);
        }
    }

    /** Epoch milliseconds of the last write, on the server's scale. */
    private(set) int $updatedAt {
        get => $this->rowValues()[self::updatedAt] ?? self::FIELD_DEFAULTS[self::updatedAt];
        set {
            $this->setRowValue(self::updatedAt, $value);
        }
    }

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
     * @param ?string $purpose Profile operation, or null for a guest send
     * @return static Fresh attempt row, queued
     */
    public static function create(
        string $sessionTokenHash,
        string $ticket,
        string $channel,
        int $updatedAt,
        ?string $purpose = null,
    ): static {
        $instance = new static();
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->ticket = $ticket;
        $instance->channel = $channel;
        $instance->purpose = $purpose;
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
        $instance->purpose = self::optionalString($row, self::purpose);
        $instance->state = self::requireString($row, self::state);
        $instance->detail = self::optionalString($row, self::detail);
        $instance->reason = self::optionalString($row, self::reason);
        $instance->resendAt = self::optionalInt($row, self::resendAt);
        $instance->expiresAt = self::optionalInt($row, self::expiresAt);
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
        $this->purpose = self::patchOptionalString($diff, self::purpose, $this->purpose);
        $this->state = self::patchString($diff, self::state, $this->state);
        $this->detail = self::patchOptionalString($diff, self::detail, $this->detail);
        $this->reason = self::patchOptionalString($diff, self::reason, $this->reason);
        $this->resendAt = self::patchOptionalInt($diff, self::resendAt, $this->resendAt);
        $this->expiresAt = self::patchOptionalInt($diff, self::expiresAt, $this->expiresAt);
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
            self::purpose => $this->purpose,
            self::state => $this->state,
            self::detail => $this->detail,
            self::reason => $this->reason,
            self::resendAt => $this->resendAt,
            self::expiresAt => $this->expiresAt,
            self::updatedAt => $this->updatedAt,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosCodeSendAttemptRowCodec();
    }

    /**
     * Prepares an unpublished row; the committed-root layer supplies its baseline later.
     *
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Fully validated candidate
     * @throws InvalidFormatException When a field violates the existing row contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public static function fromValues(RtRowReader $in): Generator
    {
        $prepared = yield from self::prepareValues($in, null);
        yield new RtWork();
        $instance = new static();
        $instance->replaceRowValues($prepared);

        return $instance;
    }

    /**
     * Every accepted field is validated before the fixed descriptor is replaced.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Atomic candidate patch
     * @throws InvalidFormatException When a changed field violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        $prepared = yield from self::prepareValues($diff, $this->rowValues());
        yield new RtWork();
        $this->replaceRowValues($prepared);
    }

    /**
     * @param RtRowWriter $out Unpublished output in any Runtime arena
     * @return Generator<int, RtWork, int, void> Bounded scalar/text export
     * @throws InvalidFormatException When a stored segmented value is malformed
     * @throws LogicException When the output has already closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /**
     * @param RtRowReader $input Segmented full row or diff
     * @param ?array<string, RtRowValues|int|float|bool|null> $current Fixed descriptor, null for restore
     * @return Generator<int, RtWork, int, array<string, RtRowValues|int|float|bool|null>> Validated fixed descriptor
     * @throws InvalidFormatException When a field violates its existing contract
     * @throws LogicException When a writer lifetime is violated
     */
    private static function prepareValues(RtRowReader $input, ?array $current): Generator
    {
        $prepared = $current ?? [];
        foreach (self::FIELD_TYPES as $key => $type) {
            yield new RtWork();
            if ($current !== null && !isset(self::PATCH_FIELDS[$key])) {
                continue;
            }
            $value = yield from $input->field($key);
            if ($value === null) {
                if ($current !== null) {
                    continue;
                }
                if (str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                throw new InvalidFormatException('Runtime row is missing required key ' . $key);
            }
            $reader = $value->reader();
            if ($reader->kind() === RtRowKind::Scalar) {
                $scalar = yield from $reader->scalar();
                if ($scalar === null && str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                $scalarType = ltrim($type, '?');
                if (($scalarType === 'int' && is_int($scalar)) || ($scalarType === 'bool' && is_bool($scalar))) {
                    $prepared[$key] = $scalar;
                    continue;
                }
                if ($scalarType === 'float' && (is_int($scalar) || is_float($scalar))) {
                    $prepared[$key] = (float)$scalar;
                    continue;
                }
            }
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }
}
