<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Runtime\State\Item;

use Demo\RobloxGameServer\Runtime\View\Context\RobloxGameServerRtContext;
use Generator;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\HilosSessionConnection;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Stands on the framework {@see HilosSessionConnection} base — the session stage,
 * because every socket of this demo belongs to one browser session. The base owns the
 * accept key, the owning user id, the session token, and the whole
 * create/hydrate/serialize/diff template; this demo adds no fields of its own, so all
 * four of its hooks are empty. Connection rows are created on handshake and removed on
 * close.
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
        return RobloxGameServerRtContext::connections;
    }

    /**
     * Nothing of this demo's own to seed: the row is the framework base.
     *
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row (nothing of this demo's own to read)
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Partial update (nothing of this demo's own to apply)
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}
