<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * Behavior - how a provider route answers one call, as a spec declared it (HIL-922).
 *
 * A spec posts the declaration to POST /test/behavior before the product makes its call, and
 * the gateway spends it on exactly one call of that route carrying that key. Four levers, each
 * optional, and they combine:
 *
 * - status: the resident's handler is not called at all and the call is refused with this
 *   status - a provider that failed delivered nothing, so no letter reaches Mailpit;
 * - delayMs: the answer is built when the call arrives, and its bytes leave this much later;
 * - cut: the status line, the headers and the first half of the body leave, then the
 *   connection closes;
 * - holdMs: after the last byte of the answer, whole or cut, the connection stays open this
 *   much longer before the gateway closes it.
 *
 * A declaration with no lever at all is legitimate: it answers as usual, which is how a spec
 * writes "then normally" inside a sequence of declarations.
 */
final readonly class Behavior
{
    /** Declaration key naming the provider route the behavior applies to. */
    public const string FIELD_PATH = 'path';

    /** Declaration key naming the value of the call the behavior is keyed by. */
    public const string FIELD_KEY = 'key';

    /** Declaration and stored key of the dictated status. */
    public const string FIELD_STATUS = 'status';

    /** Declaration and stored key of the delay before the answer, in milliseconds. */
    public const string FIELD_DELAY_MS = 'delayMs';

    /** Declaration and stored key of the cut in the middle of the answer. */
    public const string FIELD_CUT = 'cut';

    /** Declaration and stored key of the hold after the answer, in milliseconds. */
    public const string FIELD_HOLD_MS = 'holdMs';

    /** Every key a declaration may carry. */
    private const array FIELDS = [
        self::FIELD_PATH,
        self::FIELD_KEY,
        self::FIELD_STATUS,
        self::FIELD_DELAY_MS,
        self::FIELD_CUT,
        self::FIELD_HOLD_MS,
    ];

    /** Lowest status a declaration may dictate: a dictated answer is a refusal, never a success. */
    private const int STATUS_MIN = 400;

    /** Highest status a declaration may dictate. */
    private const int STATUS_MAX = 599;

    /**
     * Creates a behavior.
     *
     * @param ?int $status Status to refuse the call with, null to let the resident answer
     * @param int $delayMs Delay before the answer's bytes leave, in milliseconds
     * @param int $holdMs How long the connection stays open after the answer, in milliseconds
     * @param bool $cut Whether the answer is cut in the middle of its body
     */
    public function __construct(
        public ?int $status,
        public int $delayMs,
        public int $holdMs,
        public bool $cut,
    ) {
    }

    /**
     * Reads a declaration a spec posted, refusing it the moment one lever is wrong.
     *
     * The checks go in the order the declaration contract lists them. The path and the key are
     * not checked here: only the connection's routes know which paths are provider routes.
     *
     * @param array<string, mixed> $fields Decoded declaration
     * @return self Declared behavior; an absent lever is null, 0 or false
     * @throws InvalidBehaviorException When a key is unknown or a lever has the wrong type or range
     */
    public static function fromFields(array $fields): self
    {
        self::refuseUnknownField($fields);

        $status = $fields[self::FIELD_STATUS] ?? null;
        if ($status !== null && (!is_int($status) || $status < self::STATUS_MIN || $status > self::STATUS_MAX)) {
            throw new InvalidBehaviorException(InvalidBehaviorException::STATUS_OUT_OF_RANGE);
        }

        return new self(
            status: $status,
            delayMs: self::duration($fields, self::FIELD_DELAY_MS),
            holdMs: self::duration($fields, self::FIELD_HOLD_MS),
            cut: self::cut($fields),
        );
    }

    /**
     * Rebuilds a behavior from the form the store keeps it in.
     *
     * @param array{status: ?int, delayMs: int, cut: bool, holdMs: int} $stored Behavior as {@see toArray()} wrote it
     * @return self Stored behavior
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            status: $stored[self::FIELD_STATUS],
            delayMs: $stored[self::FIELD_DELAY_MS],
            holdMs: $stored[self::FIELD_HOLD_MS],
            cut: $stored[self::FIELD_CUT],
        );
    }

    /**
     * Refuses a declaration carrying a key it does not know.
     *
     * Its own entry point, because it is the first check of the whole declaration and the
     * path and key checks that follow it live with the connection's routes: a misspelt
     * `delay_ms` is the likeliest mistake, and any refusal reported ahead of it would hide it.
     *
     * @param array<string, mixed> $fields Decoded declaration
     * @throws InvalidBehaviorException When a key is not one of the declaration's own
     */
    public static function refuseUnknownField(array $fields): void
    {
        if (array_diff(array_keys($fields), self::FIELDS) !== []) {
            throw new InvalidBehaviorException(InvalidBehaviorException::FIELD_UNKNOWN);
        }
    }

    /**
     * The form the store keeps a behavior in.
     *
     * @return array{status: ?int, delayMs: int, cut: bool, holdMs: int} Stored behavior
     */
    public function toArray(): array
    {
        return [
            self::FIELD_STATUS => $this->status,
            self::FIELD_DELAY_MS => $this->delayMs,
            self::FIELD_CUT => $this->cut,
            self::FIELD_HOLD_MS => $this->holdMs,
        ];
    }

    /**
     * Reads a duration lever; an absent one means no wait.
     *
     * @param array<string, mixed> $fields Decoded declaration
     * @param string $field Key of the duration
     * @return int Duration in milliseconds
     * @throws InvalidBehaviorException When the duration is not a non-negative integer
     */
    private static function duration(array $fields, string $field): int
    {
        if (!array_key_exists($field, $fields)) {
            return 0;
        }

        $duration = $fields[$field];
        if (!is_int($duration) || $duration < 0) {
            throw new InvalidBehaviorException(InvalidBehaviorException::DURATION_INVALID);
        }

        return $duration;
    }

    /**
     * Reads the cut lever; an absent one means the answer leaves whole.
     *
     * @param array<string, mixed> $fields Decoded declaration
     * @return bool Whether the answer is cut
     * @throws InvalidBehaviorException When the cut is not a boolean
     */
    private static function cut(array $fields): bool
    {
        if (!array_key_exists(self::FIELD_CUT, $fields)) {
            return false;
        }

        $cut = $fields[self::FIELD_CUT];
        if (!is_bool($cut)) {
            throw new InvalidBehaviorException(InvalidBehaviorException::CUT_INVALID);
        }

        return $cut;
    }
}
