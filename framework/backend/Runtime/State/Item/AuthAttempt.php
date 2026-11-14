<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Object\Item\AuthBlock as ObjectAuthBlock;
use Hilos\Runtime\Row\AuthAttemptRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * AuthAttempt - hot window counter for one throttle key (HIL-420).
 *
 * The RT half of the anti-abuse layer's hybrid state: transient per-key attempt
 * counters kept off the database on the hot path, while consummated blocks live
 * durably in `hilos_auth_block` ({@see ObjectAuthBlock}).
 * The key is (scope, identity, action) — the same triple as the durable block —
 * where {@see scope} is one of {@see ThrottleScope} and {@see identity} is the
 * client IP or the sha256 of the session token.
 *
 * A row tracks how many attempts landed inside the current fixed window
 * ({@see count} since {@see windowStartedAt}), the escalation {@see level}
 * reached so far, and {@see blockedUntil} - the replica of the durable block's
 * moment, so a worker can refuse a blocked key without reading the database or
 * waiting on a verdict. The throttle agent owns the window/ladder arithmetic; this
 * row is a plain typed carrier that syncs across workers so counters seen by a
 * connection on one worker are visible to the same key's connections on another
 * (connections of one IP fan out to different workers). It never touches the DB.
 *
 * It is an INTERNAL op, not a browser-gated table item — nothing here is
 * surfaced to a page.
 */
final class AuthAttempt extends RtState
{
    /** Runtime collection key used for RT sync across workers. */
    public const string RT_COLLECTION = 'hilosAuthAttempts';

    public const string scope = 'scope';
    public const string identity = 'identity';
    public const string action = 'action';
    public const string count = 'count';
    public const string windowStartedAt = 'windowStartedAt';
    public const string level = 'level';
    public const string blockedUntil = 'blockedUntil';

    /** Fixed scalar schema; variable text is held in segmented pages. */
    private const array FIELD_TYPES = [
        self::scope => 'text',
        self::identity => 'text',
        self::action => 'text',
        self::count => 'int',
        self::windowStartedAt => 'float',
        self::level => 'int',
        self::blockedUntil => '?float',
    ];

    /** The synchronous property defaults, not defaults for missing incoming keys. */
    private const array FIELD_DEFAULTS = [
        self::scope => ThrottleScope::IP,
        self::identity => '',
        self::action => '',
        self::count => 0,
        self::windowStartedAt => 0.0,
        self::level => 0,
        self::blockedUntil => null,
    ];

    /** Only the fields the existing applyDiff door accepts. */
    private const array PATCH_FIELDS = [
        self::count => true,
        self::windowStartedAt => true,
        self::level => true,
        self::blockedUntil => true,
    ];

    /** Throttle scope, one of {@see ThrottleScope} (id part). */
    private(set) string $scope {
        get => isset($this->rowValues()[self::scope]) ? $this->rowValues()[self::scope]->toNative() : self::FIELD_DEFAULTS[self::scope];
        set {
            $this->setRowValue(self::scope, RtRowValues::fromNative($value));
        }
    }

    /** Client IP or sha256 of the session token, per {@see scope} (id part). */
    private(set) string $identity {
        get => isset($this->rowValues()[self::identity]) ? $this->rowValues()[self::identity]->toNative() : self::FIELD_DEFAULTS[self::identity];
        set {
            $this->setRowValue(self::identity, RtRowValues::fromNative($value));
        }
    }

    /** Throttled action name (id part). */
    private(set) string $action {
        get => isset($this->rowValues()[self::action]) ? $this->rowValues()[self::action]->toNative() : self::FIELD_DEFAULTS[self::action];
        set {
            $this->setRowValue(self::action, RtRowValues::fromNative($value));
        }
    }

    /** Attempts counted since {@see windowStartedAt}. */
    public int $count {
        get => $this->rowValues()[self::count] ?? self::FIELD_DEFAULTS[self::count];
        set {
            $this->setRowValue(self::count, $value);
        }
    }

    /** Microtime (unix seconds) the current fixed window opened; 0.0 before the first attempt. */
    public float $windowStartedAt {
        get => $this->rowValues()[self::windowStartedAt] ?? self::FIELD_DEFAULTS[self::windowStartedAt];
        set {
            $this->setRowValue(self::windowStartedAt, $value);
        }
    }

    /** Escalation level reached so far; drives the block-duration ladder. */
    public int $level {
        get => $this->rowValues()[self::level] ?? self::FIELD_DEFAULTS[self::level];
        set {
            $this->setRowValue(self::level, $value);
        }
    }

    /**
     * Unix seconds the consummated block lifts, or null when this key is not blocked.
     *
     * The replica of `hilos_auth_block`.`blocked_until` that makes the guard's fast
     * path possible: a worker refuses a blocked key off its own RT replica, without a
     * database read and without asking the agent at all. The durable row stays the
     * truth - this field is loaded from it when the agent starts.
     */
    public ?float $blockedUntil {
        get => $this->rowValues()[self::blockedUntil] ?? self::FIELD_DEFAULTS[self::blockedUntil];
        set {
            $this->setRowValue(self::blockedUntil, $value);
        }
    }

    /**
     * Composes the runtime row id from a throttle key triple.
     *
     * @param string $scope Throttle scope, one of {@see ThrottleScope}
     * @param string $identity Client IP or sha256 of the session token
     * @param string $action Throttled action name
     * @return string Runtime row id `scope|identity|action`
     */
    public static function keyFor(string $scope, string $identity, string $action): string
    {
        return $scope . '|' . $identity . '|' . $action;
    }

    /**
     * Builds a fresh counter for a throttle key with no attempts yet.
     *
     * @param string $scope Throttle scope, one of {@see ThrottleScope}
     * @param string $identity Client IP or sha256 of the session token
     * @param string $action Throttled action name
     * @return static Fresh counter at level 0 with an unopened window
     */
    public static function create(string $scope, string $identity, string $action): static
    {
        $instance = new static();
        $instance->scope = $scope;
        $instance->identity = $identity;
        $instance->action = $action;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Counter row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the counter is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->scope = self::requireString($row, self::scope);
        $instance->identity = self::requireString($row, self::identity);
        $instance->action = self::requireString($row, self::action);
        $instance->count = self::requireInt($row, self::count);
        $instance->windowStartedAt = self::requireFloat($row, self::windowStartedAt);
        $instance->level = self::requireInt($row, self::level);
        $instance->blockedUntil = self::optionalFloat($row, self::blockedUntil);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for attempt counters
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @param array<string, mixed> $diff Partial update using the same string keys as `fromRow()`
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->count = self::patchInt($diff, self::count, $this->count);
        $this->windowStartedAt = self::patchFloat($diff, self::windowStartedAt, $this->windowStartedAt);
        $this->level = self::patchInt($diff, self::level, $this->level);
        $this->blockedUntil = self::patchOptionalFloat($diff, self::blockedUntil, $this->blockedUntil);
    }

    /**
     * @return string Runtime row id, the throttle key `scope|identity|action`
     */
    public function getId(): string
    {
        return self::keyFor($this->scope, $this->identity, $this->action);
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::scope => $this->scope,
            self::identity => $this->identity,
            self::action => $this->action,
            self::count => $this->count,
            self::windowStartedAt => $this->windowStartedAt,
            self::level => $this->level,
            self::blockedUntil => $this->blockedUntil,
        ];
    }
    /** @return RtRowCodec Segmented codec preserving this row's scalar contract */
    public function rowCodec(): RtRowCodec
    {
        return new AuthAttemptRowCodec();
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
