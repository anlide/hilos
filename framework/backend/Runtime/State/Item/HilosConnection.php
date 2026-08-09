<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

/**
 * Inheritable runtime row for one WebSocket connection (HIL-361).
 *
 * The framework-owned base of the connection runtime state: it owns the session
 * triple that every project's connection carries — the WebSocket `acceptKey`
 * (the collection id, immutable), the `sessionToken` the socket belongs to
 * (immutable), and the authenticated `userId` (null while the session is
 * anonymous, re-pointed by the authenticate/deauthenticate seam). Projects
 * subclass this and add their own fields (e.g. chat: moderation + file upload),
 * composing {@see hydrateBase()} / {@see baseToArray()} / {@see applyBaseDiff()}
 * into their own {@see fromRow()} / {@see toArray()} / {@see applyDiff()}.
 *
 * This is the first inheritable RtState item: it stays abstract and does not
 * declare a runtime collection key — the concrete subclass names the collection
 * through {@see getRtCollectionKey()}.
 */
abstract class HilosConnection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string sessionToken = 'sessionToken';
    public const string userId = 'userId';

    /** WebSocket accept key (primary id). */
    private(set) string $acceptKey = '';

    /** Session cookie token this connection belongs to. */
    private(set) ?string $sessionToken = null;

    /** Authenticated database user id, or null while the session is anonymous. */
    public ?int $userId = null;

    /**
     * Runtime collection key equals the accept key.
     *
     * @return string Accept key
     */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * Seeds the session triple on a freshly created connection.
     *
     * Subclasses call this from their static `create()` factory before setting
     * their own fields and calling {@see markRtSyncBaseline()}.
     *
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param ?int $userId Authenticated user id, or null for an anonymous session
     * @param ?string $sessionToken Session cookie token this connection belongs to, or null when it belongs to none
     */
    protected function initBase(string $acceptKey, ?int $userId, ?string $sessionToken): void
    {
        $this->acceptKey = $acceptKey;
        $this->sessionToken = $sessionToken;
        $this->userId = $userId;
    }

    /**
     * Hydrates the session triple from a serialized runtime row.
     *
     * Subclasses call this from their static `fromRow()` factory before
     * hydrating their own fields.
     *
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateBase(array $row): void
    {
        $this->acceptKey = (string)$row[self::acceptKey];
        $this->sessionToken = self::stringOrNull($row[self::sessionToken] ?? null);
        $this->userId = isset($row[self::userId]) ? (int)$row[self::userId] : null;
    }

    /**
     * Returns the session-triple fields for merging into a subclass `toArray()`.
     *
     * @return array<string, mixed> Session triple (acceptKey, sessionToken, userId)
     */
    protected function baseToArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::sessionToken => $this->sessionToken,
            self::userId => $this->userId,
        ];
    }

    /**
     * Applies the mutable session-triple fields from an inbound RT diff.
     *
     * The accept key is the immutable id and is never in a diff; only the session
     * token (re-pointed on OAuth bind) and the user id (bound/unbound by the
     * authenticate seam) can change. Subclasses call this from their `applyDiff()`.
     *
     * @param array<string, mixed> $diff Partial update
     */
    protected function applyBaseDiff(array $diff): void
    {
        if (array_key_exists(self::sessionToken, $diff)) {
            $this->sessionToken = self::stringOrNull($diff[self::sessionToken]);
        }
        if (array_key_exists(self::userId, $diff)) {
            $this->userId = $diff[self::userId] === null ? null : (int)$diff[self::userId];
        }
    }

    /**
     * @param mixed $value Raw row value
     * @return ?string String value, or null
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
