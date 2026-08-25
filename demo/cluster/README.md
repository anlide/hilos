# demo/cluster — multi-node daemon-cluster e2e harness

A deliberately minimal, **backend-only** Hilos project whose job is to prove the
daemon-cluster subsystem end-to-end on one host: a pile of containers on a
dedicated bridge network, with two blunt fault switches — `docker kill -9`
(node-down / failover) and `docker network disconnect` (partition / split-brain).

It doubles as the home for the real synthetic cluster workload that the
bot-agents epic (HIL-120) will add later; for now it runs a fleet of placeable
agents whose only job is to keep their workers busy.

## Shape

- **Nodes:** 3 masters (`m1..m3`, the consensus master-set) + 2 data-plane slaves
  (`s1`, `s2`, advertising the `worker` capability). Role, identity, master set,
  and every timeout come from `CLUSTER_*` env in `docker/docker-compose.cluster.yml`.
- **Workload:** a fleet of 10 `WorkerAgent` instances (`worker:0`…`worker:9`) — on
  the node's regular workers, declared `AgentPlacement::POLICY` so the leader
  places them rather than hosting them, gated to the `worker` capability. Each one
  busies its worker with 50–250 ms jobs and reports its throughput, so a node's
  share of the load is visible in its log.
  The leader spreads the fleet over the slaves via the framework's node-selection
  policy (HIL-182) and re-places a lost node's share on failover (HIL-183).
  `ClusterDaemonManager` supplies only the placement *trigger*.
- **Assertion surface:** the read-only `test:cluster:inspect` command (HIL-325),
  run per node from the `cluster-cli` container.

## Running

```bash
composer -d demo/cluster run install-deps      # generate the lock (once)
composer -d demo/cluster run test:unit         # topology + placement-contract unit tests
demo/cluster/docker/cluster up                 # build + start mysql, 5 nodes, cli
demo/cluster/docker/cluster status             # roster + leader + placements per node
demo/cluster/docker/cluster scenarios          # the 13-scenario matrix
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
9. daemon-crash self-heal — a node whose daemon is SIGKILLed rebinds and rejoins
   inside the *same* container, then takes the whole fleet (HIL-450)
10. cross-node browser — a browser attached to one node is answered from another,
   and a fan-out reaches every node (HIL-668)
11. cross-node db fact — a database row changed on one node is announced to every
   other, and again to a node that re-linked (HIL-670)
12. rt replication — every node holds a runtime row for every fleet member, and
   every member sees the whole fleet from its own process (HIL-589)
13. rt partition converges — a node cut off from the mesh serves its replica
   frozen, and catches up from the hand-over once it is back (HIL-589)

They run in the order the driver lists them, which is not the order they are
numbered: the two RT scenarios go right after placement, while the fleet the
leader just placed is still alive. Every run starts from a fresh stack, because
the matrix leaves that fleet dead behind it (P-152).

### Timing on a loaded host (HIL-367)

The convergence caps are sized for an adequately-provisioned stand (nova-lt /
HIL-348). On a resource-constrained host the grace-driven detection windows
(keepalive-timeout + failover-grace, HIL-183) run longer than the fixed caps, so
the timing-sensitive scenarios (failover, hot-join, quorum-loss) can flake on a
pure "timed out after Ns" while the cluster logic is correct. The driver keeps
the run honest without falsely passing:

- Every cap is multiplied by an adaptive `TIMEOUT_SCALE` (>= 1.0), auto-derived
  from the host's load-per-cpu and free memory (capped at 4.0). Override it with
  `CLUSTER_E2E_TIMEOUT_SCALE=<float>`; a provisioned host resolves to 1.0.
- A scenario that fails *purely* on a convergence timeout is retried a bounded
  number of times (`CLUSTER_E2E_RETRIES`, default 1) after re-converging the
  mesh. A hard invariant assertion (wrong leader, bad placement) never retries
  and fails immediately.
