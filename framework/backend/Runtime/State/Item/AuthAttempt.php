<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Database\Object\Item\AuthBlock as ObjectAuthBlock;

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

    /** Throttle scope, one of {@see ThrottleScope} (id part). */
    private(set) string $scope = ThrottleScope::IP;

    /** Client IP or sha256 of the session token, per {@see scope} (id part). */
    private(set) string $identity = '';

    /** Throttled action name (id part). */
    private(set) string $action = '';

    /** Attempts counted since {@see windowStartedAt}. */
    public int $count = 0;

    /** Microtime (unix seconds) the current fixed window opened; 0.0 before the first attempt. */
    public float $windowStartedAt = 0.0;

    /** Escalation level reached so far; drives the block-duration ladder. */
    public int $level = 0;

    /**
     * Unix seconds the consummated block lifts, or null when this key is not blocked.
     *
     * The replica of `hilos_auth_block`.`blocked_until` that makes the guard's fast
     * path possible: a worker refuses a blocked key off its own RT replica, without a
     * database read and without asking the agent at all. The durable row stays the
     * truth - this field is loaded from it when the agent starts.
     */
    public ?float $blockedUntil = null;

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
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->scope = (string)($row[self::scope] ?? ThrottleScope::IP);
        $instance->identity = (string)$row[self::identity];
        $instance->action = (string)$row[self::action];
        $instance->count = (int)($row[self::count] ?? 0);
        $instance->windowStartedAt = (float)($row[self::windowStartedAt] ?? 0.0);
        $instance->level = (int)($row[self::level] ?? 0);
        $blockedUntil = $row[self::blockedUntil] ?? null;
        $instance->blockedUntil = $blockedUntil === null ? null : (float)$blockedUntil;
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
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::count])) {
            $this->count = (int)$diff[self::count];
        }
        if (isset($diff[self::windowStartedAt])) {
            $this->windowStartedAt = (float)$diff[self::windowStartedAt];
        }
        if (isset($diff[self::level])) {
            $this->level = (int)$diff[self::level];
        }
        // array_key_exists rather than isset: lifting a block sends blockedUntil as
        // null, and isset() would read that as "field absent" and keep the old moment.
        if (array_key_exists(self::blockedUntil, $diff)) {
            $blockedUntil = $diff[self::blockedUntil];
            $this->blockedUntil = $blockedUntil === null ? null : (float)$blockedUntil;
        }
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
}
