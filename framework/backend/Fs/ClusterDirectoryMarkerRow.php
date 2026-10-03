<?php

declare(strict_types=1);

namespace Hilos\Fs;

/**
 * The marker of a cluster directory as its file holds it ({@see ClusterDirectoryMarker::ensure()}).
 */
final readonly class ClusterDirectoryMarkerRow
{
    /**
     * @param string $marker The name of this directory: 32 lowercase hex characters
     * @param string $writtenBy CLUSTER_NODE_ID of the node that wrote the marker first
     * @param string $writtenAt When it was written, by that node's clock; printed, never compared
     */
    public function __construct(
        public string $marker,
        public string $writtenBy,
        public string $writtenAt,
    ) {
    }
}
