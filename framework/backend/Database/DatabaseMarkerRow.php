<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * The database marker as the database holds it right now ({@see DatabaseMarker::current()}).
 */
final readonly class DatabaseMarkerRow
{
    /**
     * @param string $marker The name of this logical database: 32 lowercase hex characters
     * @param string $writtenBy CLUSTER_NODE_ID of the node that wrote the marker first
     * @param string $writtenAt When it was written, by the database server's clock; printed, never compared
     */
    public function __construct(
        public string $marker,
        public string $writtenBy,
        public string $writtenAt,
    ) {
    }
}
