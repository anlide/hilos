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

    /** @var string Inspect reply key: what this node holds of each replicated RT collection */
    public const string FIELD_RT_COLLECTIONS = 'rtCollections';

    /** @var string Inspect reply key: remote RT frames this node has applied */
    public const string FIELD_RT_APPLIED = 'rtApplied';

    /** @var string Inspect reply key: remote RT frames this node refused as a two-owner split */
    public const string FIELD_RT_REFUSED = 'rtRefused';

    /** @var string Inspect reply key: RT ownership clashes this node has named as leader */
    public const string FIELD_RT_CLAIM_CONFLICTS = 'rtClaimConflicts';

    /** @var string Inspect reply key: RT claims of this node's agents the leader has refused */
    public const string FIELD_RT_CLAIM_REFUSALS = 'rtClaimRefusals';

    /**
     * @var string Inspect reply key: database collections a worker of this node reads (HIL-750).
     *     Beside the runtime rows above rather than among them: those are named per collection
     *     out of the mounted runtime, and there is no such walk for the database - what is
     *     observable is the list itself, which is exactly what decides where a frame goes.
     */
    public const string FIELD_DB_COLLECTIONS_READ = 'dbCollectionsRead';

    /** @var string RT-collection-row key: whether an agent of this node writes the collection */
    public const string FIELD_RT_OWNED = 'owned';

    /** @var string RT-collection-row key: whether this node owns it on both axes, rows and operations */
    public const string FIELD_RT_FULLY_OWNED = 'fullyOwned';

    /** @var string RT-collection-row key: whether a worker of this node reads the collection */
    public const string FIELD_RT_READ = 'read';

    /** @var string RT-collection-row key: ids of the rows this node holds under the collection */
    public const string FIELD_RT_ROW_IDS = 'rowIds';

    /** @var string RT-collection-row key: those rows themselves, keyed by id */
    public const string FIELD_RT_ROWS = 'rows';

    /**
     * @var string RT-collection-row key: those of the rows whose source node is unreachable, by
     *     id, each with the microtime it stopped being kept up to date (HIL-711). An empty object
     *     means every row of the collection is current, which is what a healthy mesh reports.
     */
    public const string FIELD_RT_STALE_ROWS = 'staleRows';

    /** @var string Inspect reply key: DB replicas from other nodes this one has accepted */
    public const string FIELD_DB_REPLICAS = 'dbReplicas';

    /** @var string Inspect reply key: collection the last accepted DB replica named */
    public const string FIELD_LAST_DB_REPLICA_COLLECTION = 'lastDbReplicaCollection';

    /**
     * @var string Column a test DB announcement puts its row id in, so the payload is not empty.
     *
     * A sync payload with no changed column is dropped before it is announced, so the drill has
     * to change something. It changes the primary key of a row that does not exist, which is the
     * one value guaranteed to land nowhere.
     */
    public const string FIELD_DB_ANNOUNCE_COLUMN = 'id';
}
