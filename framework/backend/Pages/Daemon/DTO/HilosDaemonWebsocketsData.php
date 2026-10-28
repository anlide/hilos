<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon\DTO;

use Hilos\Cluster\NodeRole;
use Hilos\DaemonSection\ClusterDaemonPicture;

/** Boundary projection of only the addressed node's reported WebSocket count. */
final readonly class HilosDaemonWebsocketsData
{
    public const string connections = 'connections';
    public const string dataNode = 'dataNode';

    /**
     * @param ?int $connections Reported sockets, null without standing or on a clustered data node
     * @param bool $dataNode Whether the addressed report belongs to a clustered data node
     */
    private function __construct(
        private ?int $connections,
        private bool $dataNode,
    ) {
    }

    /**
     * @param ?ClusterDaemonPicture $picture Current worker-local mirror
     * @param string $nodeId Requested node, never substituted with another node
     * @param bool $clustered Whether this installation is clustered
     * @param bool $hasFullSnapshot Whether the mirror received a complete snapshot
     * @return ?self Null until a complete picture contains the addressed node's report
     */
    public static function fromPicture(
        ?ClusterDaemonPicture $picture,
        string $nodeId,
        bool $clustered,
        bool $hasFullSnapshot,
    ): ?self {
        $report = $picture?->node($nodeId)?->slot?->picture;
        if (!$hasFullSnapshot || $report === null) {
            return null;
        }

        $dataNode = $clustered && $report->role === NodeRole::Slave;

        return new self($dataNode ? null : $report->standing?->connections, $dataNode);
    }

    /** @return array{connections: ?int, dataNode: bool} Only the page's own WebSocket fields */
    public function toArray(): array
    {
        return [self::connections => $this->connections, self::dataNode => $this->dataNode];
    }
}
