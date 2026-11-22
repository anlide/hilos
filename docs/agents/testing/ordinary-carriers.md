# Testing — Ordinary Demo Carriers

Read this when a check belongs on one of the twelve ordinary demos: their
activation state, running and cleaning up an active ordinary stand, the runner's
isolation and comparison budget, and where the ordinary phases end and the
cluster ones begin.

## Ordinary demo carriers

HIL-1984 approves twelve **ordinary demos**, four per view framework. This
count excludes the framework stands and the existing cluster fleets. Active
ordinary demos are registered in `framework-projects.json` and the test runner;
the remaining planned rows are activation targets, not working applications
merely because a directory or README exists.

| View | Ordinary demo | Current state / activation owner |
|---|---|---|
| Vue | `chat` | Active |
| Vue | `binance-btc-tracker` | Active |
| Vue | `pmd` | Active |
| Vue | `roblox-game-server` | Active |
| React | `tasks` | Active |
| React | `ecommerce-shop` | Active |
| React | `solar-system-model` | Active |
| React | `file-gallery-manager` | Active |
| Angular | `polls` | Active |
| Angular | `online-testing` | Active |
| Angular | `preferans` | Active |
| Angular | `concert-tickets` | Active |

`hilos-website` is not an ordinary demo but the official site (HIL-2147): thin
on purpose and left out of every "in every demo" rollout. It is registered in
`framework-projects.json` and in the runner, but has no e2e step — the runner
gives it deps, check and php, and what a person sees there the owner accepts by
hand (HIL-2185, 18.11.2026: the fixed part of an e2e step costs 150–450 s per
stand, while the site's own tests would take about 40 s).

Each activation in this table covers its complete obligation: an
application shell with an empty home page, AUTH/sign-in, working Analytics in
the administrative section, ordinary local/test stands, the command interface
below, and registration in the existing runner and stand registry. Each new
demo proves its own guest opening, sign-in and real Analytics data; a menu
entry alone does not prove Analytics works. Administrative placement keeps the
normal admin view mode and its masking rules. The exact framework areas each
activation takes, their current paths and the whole-area transfer rule live in
[Which demo carries a spec](../frontend/testing-strategy.md#which-demo-carries-a-spec--the-server-check-travels-the-product-stays-home).

PMD is an active Vue carrier with its service database and transferred framework
specs. Its local and test stacks do not add it to the separate six-demo cluster
stand matrix of [cluster-stands.md](cluster-stands.md).
Solar is an active React carrier. It permits guest access to its public Model
and authenticated access to its administration and Analytics. Its public header
has no sign-in control; sign-in opens as a form in place on `/hilos`, a page
closed to a guest. The Model product itself is outside this activation phase.

File Gallery Manager is an active React carrier. Its home page is empty, its
header opens sign-in with its own control, and its administration and Analytics
are for authenticated people. It carries the transferred i18n lifecycle,
Daemon, Change Log, Communications and Users specs. The gallery product itself
is outside this activation phase.

[`framework-projects.json`](../../../framework-projects.json)
lists the active projects; [`scripts/test-suite.php`](../../../scripts/test-suite.php)
and [`scripts/test-stands.php`](../../../scripts/test-stands.php) register their
ordinary test suites and stands alongside the existing cluster and framework
stands. An activation updates these facts and documents its actual commands
when its code lands, clearing its markers in that same commit under
[the rule-authoring contract](../rule-authoring.md#a-rule-written-ahead-of-its-code).

### Run and clean up an active ordinary stand

From the repository root, use the Chat commands and PMD geometry command below:

```bash
composer -d demo/chat run test:e2e-full
composer -d demo/pmd run test:e2e-full -- tests/table-geometry.spec.ts
composer -d demo/chat run test:e2e-full -- --grep "<title>"
composer -d demo/chat run test:down
```

The middle two lines are alternative pointed runs; replace `<title>` with the
test title. `test:e2e-full` runs teardown → base infrastructure up → frontend
build → e2e install → e2e check → e2e up → tests → teardown. Every link except
`@test:e2e` retains `@no_additional_args`, so a selector reaches only the test
entry. `test:e2e-up` prepares the database and starts the application and nginx.
`test:up` alone starts the base infrastructure, not the whole browser stand.
Use the [clean rerun rule](selective.md#re-running-tests-and-state-between-runs).

Composer stops its chain on a failed link, so the final teardown is not a
guarantee after failure. Preserve the diagnostic evidence, then run
`test:down` on that same instance after either outcome. Repository-wide cleanup
is the existing `composer run test:stands:down`, backed by
[`scripts/down-stands.php`](../../../scripts/down-stands.php); it selects the
registered stands of the current instance, including any raised by hand.

For each remaining planned demo, `test:e2e-full`, its pointed form, `test:down`
and `test:e2e-time-zone` are the **required activation interface**, owned by
that demo's marker in the table above. These commands are not yet available in
those planned projects. Each activation supplies the same argument isolation
and shared Playwright entry; the existing
[quarantine](full-run.md#playwright-quarantine) and
[browser time-zone](../frontend/testing-strategy.md#explicit-browser-time-zones)
rules apply without a second set of rules here.

### Runner, isolation and the comparison budget

Keep the existing `composer run test:suite` runner and
`composer run test:frontend:all` selection. Add each activated demo through the
manifest and stand registry, under its activation owner above; do not add a
second runner. Preserve the `fe-build` dependency, the per-demo group, step
snapshots and the instance's teardown. A step declaring `downsStand` takes its
stand down after its snapshot on every outcome; ordinary failed stands may
remain for diagnosis and still owe the explicit cleanup above.

The suffix, network/address, host-port and CPU-share isolation described under
[stand-compose.md](stand-compose.md) still applies.
The address map in
[`scripts/stand-instance.php`](../../../scripts/stand-instance.php) is three blocks.
The first holds its ten assigned stands. The second holds six reserved ordinary
demo addresses, `HILOS_STAND_NET_PMD` through
`HILOS_STAND_NET_CONCERT_TICKETS`, on default octets 214 to 219; Preferans and
Concert Tickets use two of these addresses, while the other planned carriers
retain theirs. The third holds three cluster stands,
`HILOS_STAND_NET_ROBLOX_GAME_SERVER_CLUSTER`,
`HILOS_STAND_NET_FILE_GALLERY_MANAGER_CLUSTER` and
`HILOS_STAND_NET_CONCERT_TICKETS_CLUSTER`, on default octets 225, 227 and 229.
The test compose of each activation reads its own
variable, and its ports and local subnet are reserved in the registry
([../new-project/README.md](../../new-project/README.md)).

**Seven lanes is the agreed comparison condition for HIL-1984 on the test
box**, not a replacement for the adaptive lane-count formula. Do not increase
the lane or instance count to manufacture a gain. HIL-2023 measured the full
run across activation and transfer. The boundary: seven lanes on the test box,
regular full runs, each timed from its start to its finish. Before the
transfers — runs 2232…2283, six demos, n=21 — the median was 26.8 minutes
(p25 25.7); after them — runs 2345…2377, twelve demos, n=20 — it is 28.1
minutes (p25 27.5). The run is held by the sum of its steps, not by the chain
of one demo. The result and the final run, with its residue check, are recorded
in HIL-2023, main and quarantine observations apart; the historical durations
above promise no new speedup.

### Ordinary and cluster phases

HIL-1984 ends at the twelve ordinary demos: the base shell, sign-in and
Analytics, whole-area transfers, runner registration, isolation, cleanup and
measurement. `roblox-game-server`, `file-gallery-manager` and `concert-tickets`
are ordinary carriers in this phase. Their additional Hilos clusters,
specialized MariaDB topologies, journals across the database topology,
fault/recovery proofs and cluster-browser transfers belong to HIL-1985. The
phase-two matrix is in [The cluster stands](cluster-stands.md#the-cluster-stands--six-demos-six-shapes).
The three existing stands retain their proofs except for cluster-browser areas
that moved within their view framework under the
[approved target](../frontend/testing-strategy.md#approved-cluster-browser-target--hil-1985).

The remaining ordinary carrier activations do not build their products. PMD
without a service database or with managed external databases, CLI/MCP/3D/MCP
Apps, and Software Factory are outside this phase.
Solar's Model product belongs to HIL-193/HIL-132; Software Factory waits for
the Tasks product. Analytics here means activating the existing section, not
the future Visits/cubes/rebuild work of HIL-1402.

