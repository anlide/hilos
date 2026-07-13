<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Wire keys for the cluster command-channel payloads.
 *
 * These name the fields of the `cluster:nodes` reply payload so the CLI client
 * and the daemon agree on the shape without magic strings.
 */
final class ClusterCommandConstants
{
    /** @var string Reply key: whether cluster mode is enabled on the daemon */
    public const string FIELD_ENABLED = 'enabled';

    /** @var string Reply key: list of node rows the daemon knows about */
    public const string FIELD_NODES = 'nodes';

    /** @var string Node-row key: node id */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Node-row key: node role */
    public const string FIELD_NODE_ROLE = 'role';

    /** @var string Node-row key: declared capability tags */
    public const string FIELD_NODE_CAPABILITIES = 'capabilities';
}
