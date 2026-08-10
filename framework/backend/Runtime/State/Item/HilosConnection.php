<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

/**
 * Inheritable runtime row for one WebSocket connection — the presence stage (HIL-361, HIL-509).
 *
 * The framework-owned base every project's connection stands on: it owns the pair
 * that presence is made of — the WebSocket `acceptKey` (the collection id,
 * immutable) and the authenticated `userId` (null while the connection is
 * anonymous, re-pointed by the authenticate/deauthenticate seam). A project that
 * also carries browser sessions stands on {@see HilosSessionConnection} instead,
 * the stage above this one; choosing the stage is the only way to not carry a
 * field, because a subclass cannot drop a property its parent declares.
 *
 * Composition of the base row with the project's own fields cannot be skipped:
 * {@see fromRow()}, {@see toArray()} and {@see applyDiff()} are final and run the
 * base half themselves, and the project half is four abstract hooks — {@see
 * initOwn()}, {@see hydrateOwn()}, {@see ownToArray()}, {@see applyOwnDiff()} —
 * that PHP will not let a subclass leave unimplemented. The base half runs first
 * and the project half is merged over it, so a project field named like a base one
 * takes the key and hides it: a project names its fields around the base, it never
 * restates one. {@see create()} is the one exception to final:
 * {@see HilosSessionConnection} widens its signature with the session token, and a
 * final method cannot be overridden.
 *
 * This is the first inheritable RtState item: it stays abstract and does not
 * declare a runtime collection key — the concrete subclass names the collection
 * through {@see getRtCollectionKey()}.
 */
abstract class HilosConnection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';

    /** WebSocket accept key (primary id). */
    private(set) string $acceptKey = '';

    /** Authenticated database user id, or null while the connection is anonymous. */
    public ?int $userId = null;

    /**
     * Creates a connection row for a freshly opened socket.
     *
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param ?int $userId Authenticated user id, or null for an anonymous connection
     * @return static Connection row ready for the collection
     */
    public static function create(string $acceptKey, ?int $userId): static
    {
        $instance = new static();
        $instance->initBase($acceptKey, $userId);
        $instance->initOwn();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Restores a connection row from a serialized runtime row.
     *
     * @param array<string, mixed> $row Serialized runtime row (keys match the field constants)
     * @return static Connection row restored from a sync row
     */
    final public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->hydrateBase($row);
        $instance->hydrateOwn($row);
        $instance->markRtSyncBaseline();

        return $instance;
    }

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
     * @return array<string, mixed> Row for persistence / truth-source sync
     */
    final public function toArray(): array
    {
        return array_merge($this->baseToArray(), $this->ownToArray());
    }

    /**
     * @param array<string, mixed> $diff Partial update; keys are the row field constants
     */
    final public function applyDiff(array $diff): void
    {
        $this->applyBaseDiff($diff);
        $this->applyOwnDiff($diff);
    }

    /**
     * Seeds the project's own fields on a freshly created connection.
     *
     * Runs after the base fields are seeded. A project whose row is nothing but
     * the base leaves this empty.
     */
    abstract protected function initOwn(): void;

    /**
     * Hydrates the project's own fields from a serialized runtime row.
     *
     * @param array<string, mixed> $row Serialized runtime row
     */
    abstract protected function hydrateOwn(array $row): void;

    /**
     * Returns the project's own fields, merged over the base ones by {@see toArray()}.
     *
     * @return array<string, mixed> Project fields of this row (empty when it has none)
     */
    abstract protected function ownToArray(): array;

    /**
     * Applies the project's own mutable fields from an inbound RT diff.
     *
     * @param array<string, mixed> $diff Partial update
     */
    abstract protected function applyOwnDiff(array $diff): void;

    /**
     * Seeds the base fields on a freshly created connection.
     *
     * A detail of the base, not a contract for projects: the stages chain it
     * through `parent::` inside the framework, and a project reaches its own
     * fields through {@see initOwn()}.
     *
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param ?int $userId Authenticated user id, or null for an anonymous connection
     */
    protected function initBase(string $acceptKey, ?int $userId): void
    {
        $this->acceptKey = $acceptKey;
        $this->userId = $userId;
    }

    /**
     * Hydrates the base fields from a serialized runtime row.
     *
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateBase(array $row): void
    {
        $this->acceptKey = (string)$row[self::acceptKey];
        $this->userId = isset($row[self::userId]) ? (int)$row[self::userId] : null;
    }

    /**
     * Returns the base fields {@see toArray()} merges the project ones over.
     *
     * @return array<string, mixed> Base fields (acceptKey, userId)
     */
    protected function baseToArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
        ];
    }

    /**
     * Applies the mutable base fields from an inbound RT diff.
     *
     * The accept key is the immutable id and is never in a diff; only the user id
     * (bound and unbound by the authenticate seam) can change.
     *
     * @param array<string, mixed> $diff Partial update
     */
    protected function applyBaseDiff(array $diff): void
    {
        if (array_key_exists(self::userId, $diff)) {
            $this->userId = $diff[self::userId] === null ? null : (int)$diff[self::userId];
        }
    }
}
