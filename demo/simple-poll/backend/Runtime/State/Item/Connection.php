<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Runtime\State\Item;

use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Minimal presence core: only the accept key and the owning user id. Connection
 * rows are created on handshake and removed on close; their fields never change
 * while open, so no diff/update path is needed.
 */
final class Connection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';

    /** WebSocket accept key (primary id). */
    private(set) string $acceptKey = '';

    /** Owning database user id. */
    private(set) int $userId = 0;

    /**
     * Creates a connection state for a freshly opened socket.
     *
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param int $userId Owning user id
     * @return static Connection state ready for the collection
     */
    public static function create(string $acceptKey, int $userId): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (keys match this class field constants)
     * @return static Connection state restored from a sync row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = (string)$row[self::acceptKey];
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for connection rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return PollRtContext::connections;
    }

    /**
     * @return string Runtime collection key (same as the accept key)
     */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, mixed> Row for truth-source sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
        ];
    }
}
