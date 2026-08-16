<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Runtime\State\Item;

use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Hilos\Runtime\State\Item\HilosSessionConnection;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Stands on the framework {@see HilosSessionConnection} base — the session stage
 * since HIL-407, because this demo now carries browser sessions and every socket
 * belongs to one. The base owns the accept key, the owning user id, the session
 * token, and the whole create/hydrate/serialize/diff template; this demo adds no
 * fields of its own, so all four of its hooks are empty. Connection rows are
 * created on handshake and removed on close.
 */
final class Connection extends HilosSessionConnection
{
    /**
     * Runtime collection key for connection rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return TodoRtContext::connections;
    }

    /**
     * Nothing of this demo's own to seed: the row is the framework base.
     */
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of this demo's own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of this demo's own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
