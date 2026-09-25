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
- **Second claimer:** one `ClaimerAgent` (`claimer:0`) declared the same way but
  claiming the *whole* of `workerStatuses`, which the fleet owns row by row — the
  two-owner split the cluster-wide guard exists to name (HIL-696). Nothing starts
  it: indexed policy-placed agents are outside the framework's placement sweep and
  the demo's own supervisor knows only the fleet, so it reaches the mesh only when
  a scenario asks for it with `test:cluster:agent:place`. It writes nothing.
- **Assertion surface:** the read-only `test:cluster:inspect` command (HIL-325),
  run per node from the `cluster-cli` container.
- **Peer TLS:** every link between nodes is mutual TLS against the stand's own
  authority, and each node presents a certificate carrying its node id (HIL-1034).
  The files live in `docker/tls/` — see [TLS fixtures](#tls-fixtures). A sixth
  node, `x1` (`cluster-x1`, compose profile `intruder`), is certified by an
  authority the cluster does not trust; `cluster intruder up|down` drives it, and
  only scenario 17 uses it.

## Running

```bash
composer -d demo/cluster run install-deps      # generate the lock (once)
composer -d demo/cluster run test:unit         # topology + placement-contract unit tests
demo/cluster/docker/cluster up                 # build + start mysql, 5 nodes, cli
demo/cluster/docker/cluster status             # roster + leader + placements per node
demo/cluster/docker/cluster scenarios          # the scenario matrix
demo/cluster/docker/cluster down --volumes     # tear everything down
```

`composer -d demo/cluster run test:cluster:all` runs the unit suite then the
scenario matrix. From the repo root: `composer run test:cluster:all`.

## Scenario matrix (`docker/cluster_e2e.py`)

1. master-slave mesh — exactly one leader, slaves follow
2. master-master — one leader among masters, slaves never lead
3. placement — the no-op agent is placed and started on a data-plane node
4. slave-kill failover — the leader re-places the agent onto the other slave
5. leader-kill re-election — survivors elect a new leader within the timeout,
   and the fleet the new leader inherits keeps running past its slaves' fence
   window (HIL-440)
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
14. rt claim refused — an agent claiming a collection another node already owns
   is named, stopped, and never re-placed; the fleet keeps its rows (HIL-696)
15. db interest addressing — a database fact hops only to the nodes that read the
   collection it names, and each node reports which those are (HIL-750)
16. recreated node leaves no phantom fleet — a data-plane container replaced faster
   than the failover grace comes back hosting nothing and says so, the fleet ends up
   running again, and the leader names no node that runs none of it (HIL-719)
17. foreign certificate refused — a node certified by an authority the cluster does
   not trust is refused on both ends of the link, named in the log of both, and
   listed by nobody, while the five still converge (HIL-1034)
18. capacity is consumed — ballast fills the slaves in proportion to their declared
   ram, never lands on a master, and a full cluster places no more (HIL-448)
19. worker death on a live node — one worker process of a slave is SIGKILLed:
   the node names the agents it lost, the leader places exactly those again, and
   the members on its other workers run on untouched (HIL-440)

They run in the order the driver lists them, which is not the order they are
numbered: the three RT scenarios and scenario 19 go right after placement, while
the fleet the leader just placed is still spread over both slaves. Scenario 19
therefore keeps members on workers of its victim that survive. That order was
forced by a defect — the matrix
used to leave the fleet dead behind it (P-152) — and it is kept now that the defect
is gone, because moving a scenario moves its timing with it. Every run still starts
from a fresh stack: the matrix kills, partitions and recreates every node it
touches, so there is nothing in a used one worth keeping.

### Timing on a loaded host (HIL-367)

The convergence caps are sized for an adequately-provisioned stand (nova-lt /
HIL-348). On a resource-constrained host the grace-driven detection windows
(keepalive-timeout + failover-grace, HIL-183) run longer than the fixed caps, so
the timing-sensitive scenarios (failover, hot-join, quorum-loss) can flake on a
pure "timed out after Ns" while the cluster logic is correct. The driver keeps
the run honest without falsely passing:

- Every cap is multiplied by a `TIMEOUT_SCALE` (>= 1.0, capped at 4.0). The test
  runner exports `CLUSTER_E2E_TIMEOUT_SCALE` from the lane count it resolved, and
  that value is a **floor**: free memory may raise the factor above it, while the
  load-per-cpu term steps aside. Set the variable by hand to pin a factor; with
  it unset the factor is derived from load and memory as before, and a
  provisioned host resolves to 1.0.
- A scenario that fails *purely* on a convergence timeout is retried a bounded
  number of times (`CLUSTER_E2E_RETRIES`, default 1) after re-converging the
  mesh. A hard invariant assertion (wrong leader, bad placement) never retries
  and fails immediately.

## TLS fixtures

`docker/tls/` holds the certificates of this stand, and they are **stand fixtures
only**: the authority behind them signs nothing else, and its key is not in the
repository. Nothing here is a template for a real cluster — issue your own.

| File | What it is | Used by |
|---|---|---|
| `ca.pem` | the stand authority's certificate, no key | `CLUSTER_TLS_CA_FILE` of the five nodes |
| `m1.pem` … `s2.pem` | a node certificate (CN = node id) followed by its key | `CLUSTER_TLS_CERT_FILE` of that node |
| `x1.pem` | node `x1`, signed by a *foreign* authority | the intruder of scenario 17 |
| `intruder-ca.pem` | that foreign authority's certificate | `x1`'s trust file, so `x1` passes its own start-up check |

Reissuing means a new set in full: the old authority's key is gone, so no single
file can be replaced alone. The framework's own commands print PEM to stdout, and
the host shell writes the files, so they come out owned by you rather than root.
Keep both authority files (`cluster-ca.pem`, `foreign-ca.pem`) outside the
repository and delete them when done:

```bash
cli() {  # one framework CLI command in a throwaway container, no network needed
  docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/demo/cluster":/app:ro -v "$PWD/composer.json":/hilos/composer.json:ro \
    -v "$PWD/composer.lock":/hilos/composer.lock:ro -v "$PWD/framework":/hilos/framework:ro \
    -v /tmp/cluster-ca:/ca:ro -w /app -e APP_ENV=dev \
    hilos-cluster-cluster-cli:latest php backend/Bootstrap/cli.php "$@"
}
mkdir -p /tmp/cluster-ca && t=demo/cluster/docker/tls
cli cluster:tls:ca > /tmp/cluster-ca/cluster-ca.pem
cli cluster:tls:trust /ca/cluster-ca.pem > $t/ca.pem
for n in m1 m2 m3 s1 s2; do cli cluster:tls:issue $n /ca/cluster-ca.pem > $t/$n.pem; done
# the intruder: a second, foreign authority
cli cluster:tls:ca > /tmp/cluster-ca/foreign-ca.pem
cli cluster:tls:trust /ca/foreign-ca.pem > $t/intruder-ca.pem
cli cluster:tls:issue x1 /ca/foreign-ca.pem > $t/x1.pem
rm -r /tmp/cluster-ca
```

The node certificates are valid for ten years (the authority's lifetime); a node
warns in its log from 30 days before the end.
