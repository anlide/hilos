# demo/cluster — multi-node daemon-cluster e2e harness

A deliberately minimal, **backend-only** Hilos project whose job is to prove the
daemon-cluster subsystem end-to-end on one host: a pile of containers on a
dedicated bridge network, with two blunt fault switches — `docker kill -9`
(node-down / failover) and `docker network disconnect` (partition / split-brain).

It doubles as the home for the real synthetic cluster workload that the
bot-agents epic (HIL-120) will add later; for now the only agent is a placeable
no-op the harness observes.

## Shape

- **Nodes:** 3 masters (`m1..m3`, the consensus master-set) + 2 data-plane slaves
  (`s1`, `s2`, advertising the `worker` capability). Role, identity, master set,
  and every timeout come from `CLUSTER_*` env in `docker/docker-compose.cluster.yml`.
- **One agent:** `WorkerAgent` — monopolistic, **not** a cluster-singleton
  (`requiresClusterLeadership() = false`), gated to the `worker` capability. The
  leader places it on a slave via the HIL-179 primitive and the framework
  re-places it on failover (HIL-183). `ClusterDaemonManager` supplies the
  placement *trigger* (node-selection policy is the still-open HIL-182).
- **Assertion surface:** the read-only `cluster:test:inspect` command (HIL-325),
  run per node from the `cluster-cli` container.

## Running

```bash
composer -d demo/cluster run install-deps      # generate the lock (once)
composer -d demo/cluster run test:unit         # topology + placement-contract unit tests
demo/cluster/docker/cluster up                 # build + start mysql, 5 nodes, cli
demo/cluster/docker/cluster status             # roster + leader + placements per node
demo/cluster/docker/cluster scenarios          # the 8-scenario matrix
demo/cluster/docker/cluster down --volumes     # tear everything down
```

`composer -d demo/cluster run test:cluster:all` runs the unit suite then the
scenario matrix. From the repo root: `composer run test:cluster:all`.

## Scenario matrix (`docker/cluster_e2e.py`)

1. master-slave mesh — exactly one leader, slaves follow
2. master-master — one leader among masters, slaves never lead
3. placement — the no-op agent is placed and started on a data-plane node
4. slave-kill failover — the leader re-places the agent onto the other slave
5. leader-kill re-election — survivors elect a new leader within the timeout
6. hot-join — a returning node is admitted; inspect shows the full roster
7. quorum-loss — an isolated minority master stops leading; no new leader
8. split-brain prevention — the majority keeps one leader; the minority steps down
