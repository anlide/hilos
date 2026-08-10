<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Runtime\State\Item;

use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Hilos\Runtime\State\Item\HilosConnection;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Stands on the framework {@see HilosConnection} base — the presence stage,
 * because this demo carries no browser sessions and therefore no session token.
 * The base owns the accept key, the owning user id, and the whole
 * create/hydrate/serialize/diff template; this demo adds no fields of its own,
 * so all four of its hooks are empty. Connection rows are created on handshake
 * and removed on close.
 */
final class Connection extends HilosConnection
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
