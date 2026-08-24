<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Wire keys for the cluster command-channel payloads.
 *
 * These name the fields of the `cluster:nodes` reply payload, and of the richer
 * `test:cluster:inspect` reply (HIL-325), so the CLI client and the daemon agree
 * on the shape without magic strings.
 */
final class ClusterCommandConstants
{
    /** @var string Reply key: whether cluster mode is enabled on the daemon */
    public const string FIELD_ENABLED = 'enabled';

    /** @var string Reply key: whether a cluster:reload changed the local node record */
    public const string FIELD_CHANGED = 'changed';

    /** @var string Reply key: list of node rows the daemon knows about */
    public const string FIELD_NODES = 'nodes';

    /** @var string Node-row key: node id */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Node-row key: node role */
    public const string FIELD_NODE_ROLE = 'role';

    /** @var string Node-row key: declared capability tags */
    public const string FIELD_NODE_CAPABILITIES = 'capabilities';

    /** @var string Node-row key: whether the node is currently online */
    public const string FIELD_NODE_ONLINE = 'online';

    /** @var string Inspect node-row key: microtime the node was last observed */
    public const string FIELD_NODE_LAST_SEEN = 'lastSeen';

    /** @var string Inspect reply key: id of the local node whose view this snapshot is */
    public const string FIELD_LOCAL_NODE_ID = 'localNodeId';

    /** @var string Inspect reply key: the local node's coarse lifecycle phase name */
    public const string FIELD_LIFECYCLE_STATE = 'lifecycleState';

    /** @var string Inspect reply key: node id the local node recognises as leader, or null */
    public const string FIELD_LEADER_ID = 'leaderId';

    /** @var string Inspect reply key: current election term, or null off consensus */
    public const string FIELD_TERM = 'term';

    /** @var string Inspect reply key: local consensus role value, or null off consensus */
    public const string FIELD_CONSENSUS_ROLE = 'consensusRole';

    /** @var string Inspect reply key: whether the local node currently sees a quorum */
    public const string FIELD_HAS_QUORUM = 'hasQuorum';

    /** @var string Inspect reply key: list of leader-tracked placement rows */
    public const string FIELD_PLACEMENTS = 'placements';

    /** @var string Placement-row key: placed agent type */
    public const string FIELD_PLACEMENT_AGENT_TYPE = 'agentType';

    /** @var string Placement-row key: placed agent index, or null for a singleton */
    public const string FIELD_PLACEMENT_AGENT_INDEX = 'agentIndex';

    /** @var string Placement-row key: derived agent id ("type" or "type:index") */
    public const string FIELD_PLACEMENT_AGENT_ID = 'agentId';

    /** @var string Placement-row key: last-known placement state value */
    public const string FIELD_PLACEMENT_STATE = 'state';

    /**
     * @var string Signal name the test-only client commands raise, and the browser side of the
     *     mesh answers to
     *
     * A name of its own rather than a page's, because the point of these scenarios is the
     * transport under the pages: the harness drives it from a command line, on a demo that has
     * no browser at all, and asserts on what the receiving node accepted.
     */
    public const string SIGNAL_CLIENT_TEST = 'cluster_client_test';

    /** @var string Inspect reply key: how many browser connections this node's index holds per node */
    public const string FIELD_CLIENT_INDEX = 'clientIndex';

    /** @var string Inspect reply key: cross-node deliveries this node has accepted for its browsers */
    public const string FIELD_CLIENT_DELIVERIES = 'clientDeliveries';

    /** @var string Inspect reply key: browser the last addressed cross-node delivery was for */
    public const string FIELD_LAST_CLIENT_ACCEPT_KEY = 'lastClientAcceptKey';
}
