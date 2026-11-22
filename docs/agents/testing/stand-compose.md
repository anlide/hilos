# Testing — A Stand's Compose File

Read this when adding or changing a test stand's compose file or one of its
services: instance suffixes and network octets, CPU weight, log rotation, the
stand's own data root, and which identity writes into the clone.

**Stand instances.** Several copies of every stand can stand on one host at once, one
per working tree. An instance is a number from 1 to 9:
`eval "$(php scripts/stand-instance-env.php <n>)"` exports its environment, and
everything run in that shell — the runner, a demo's composer script, a teardown —
lands on that instance and leaves the others alone. The instance appends `-s<n>` to
every compose project, container, explicitly named volume and network and `/dev/shm`
directory, moves each stand's `10.x.0.0/16` network (and every fixed address on it) to
an octet of its own, and leaves host ports to docker. Without those variables a stand
is exactly what its compose file says: the default instance. Service keys are not
renamed — they are the host names inside a stand's own network. A new compose file
that pins a name, a subnet, an address or a host path takes `${HILOS_STAND_SUFFIX:-}`
or its stand's `HILOS_STAND_NET_*` octet the same way; the table of octets lives in
[`scripts/stand-instance.php`](../../../scripts/stand-instance.php). The table is
written in blocks: the octet an instance occupies does not shift, and a new stand
opens its own block rather than extending an old one.
`framework/tests/Unit/ClusterStandIsolationGuardTest.php` holds every cluster stand
to this, and a stack with a `docker/docker-compose.cluster.yml` needs its row in
`scripts/test-stands.php`.

**CPU weight of a stand.** Every stand service reads `cpu_shares` from
`HILOS_STAND_CPU_SHARES`; unset, it is `0`, which docker reads as its own default.
A check run beside a full run on another instance lowers it, so the full run keeps
the processor when both want it. A new stand service takes the same line.

**Log rotation of a stand.** Every service that runs a daemon sets
`LOG_ROTATION_CRON=0 3 29 2 *`, in `docker/cluster/common.env` on a cluster stand,
so a test stand never rotates its logs on its own at night. A scenario that needs a
rotation asks for one: see
[Time](../frontend/testing-strategy.md#time--a-command-brings-the-moment-to-now).
`framework/tests/Unit/StandLogRotationGuardTest.php` holds every `demo/` row of
`scripts/test-stands.php` to this, so a new demo takes the same line.

**Data root of a stand.** Every stand service that mounts the project at `/app`
(`..:/app` or `../..:/app`) mounts the stand's own root at `/app/data` as well: on an
ordinary test stack `../data/test:/app/data`, the tree's host `data/test/` with no
instance suffix, since an instance is one per tree; on a cluster stand the stand's
named volume `data:/app/data`, `name: hilos-<demo>-cluster-data${HILOS_STAND_SUFFIX:-}`,
on every node and the cluster's CLI. Any other mount whose target lies inside
`/app/data` comes from the stand's storage only — on an ordinary stack a
subdirectory of `../data/test/` or a named volume, on a cluster stand a named volume
alone (`../data/test/…` from `docker/cluster/` is not the stand's storage) — and takes
no variable in its source or its target, `$NAME` as well as `${…}` (`$$` writes a
literal `$` and is none): a path from the shell leads it out. Agents clean their
storage by their own database — an export agent deletes, at start and every hour,
each file of its directory without a ready row (`expireCopies()` of
`AbstractDataExportAgent`, `expire()` of `LegalAcceptancesExports`) — and a test
database after `db-reset` is empty, so a stand without its own root would delete the
local installation's exports, empty its backups (the `test:backup:reset` step of `test:stand:reset`),
overwrite its Status with the stand's publication, and write its own files among the
local ones. On an ordinary stack the test nginx reads exports, Status and files from
the subdirectories of `data/test/`, an e2e runner, which sees the repository at
`/hilos`, follows the stand's files there (`data/test/status`, `data/test/backup`),
and the stand does not set `BACKUP_DIR`: where a demo declares a default (chat,
tasks, binance-btc-tracker and ecommerce-shop: `data/backup`), it lies under `data/`
and so in the root; polls, pmd, preferans, concert-tickets and online-testing keep the framework's
empty default and have no backup storage on their stand, so the `test:backup:reset`
step of `test:stand:reset` on polls finds nothing to empty. A cluster stand's export and
Status volumes stay separate volumes over its root, because an entry reads them at
its own `/data_export` and `/status` and its part is shared with a standalone
deployment; the harness clears every volume of the stand each run.
`framework/tests/Unit/StandDataRootGuardTest.php` holds every `demo/` row of
`scripts/test-stands.php` to this data isolation rule. A new demo meets it with
its registry row. An nginx reads the stand's data; an incorrect data source there
shows as a red e2e.

On a clone owned by a non-root user, a test stand leaves no newly root-owned file
or directory in the clone. Git provides each demo's data/ parent. Test writers
prepare their declared directories, repair old root-owned outputs there and
execute as the bind root's owner. A root process may read a read-only clone and
write to its own container storage: the framework PHPUnit runner does so, while
its Composer installer writes as the clone's owner. A clone whose bind root is
owned by root keeps its existing behavior.

Every demo with a test stand tracks `data/.gitignore`, so Docker does not create
that parent as root. A service with a writable clone bind uses
`framework/docker/test-writer-entrypoint.sh`, declares `HILOS_TEST_OWNER_ROOT`
as its writable `/app` or `/hilos` bind root and lists nonempty, explicit absolute
output directories in `HILOS_TEST_WRITE_DIRS`. The optional `HILOS_TEST_OWN_DIRS`
prepares parents without recursively taking their contents. The scripts discover
UID and GID from the bind root; there is no UID/GID override. Preparation creates
missing output parents and repairs old root-owned outputs without following
symlinks. `framework/docker/test-writer-exec.sh` then executes with that identity,
without supplementary root groups, preserving cwd, arguments and exit status.
Application CLI calls through `docker exec` use this cheap exec wrapper too;
preparation belongs to service start, not to each exec. Database tooling keeps
its image's identity and does not run application code through a root shell.

Framework PHPUnit keeps `/hilos:ro`, with its cache and fixtures in container
storage outside the clone; its permission and SSH shipping checks retain root
and their root-only keys. `hilos-deps-test` is the separate Composer writer, with
an accessible Composer cache. Read-only readers need no writer entrypoint. A
writable child bind under a read-only clone root still needs a writable owner
root; the root-reader allowance does not cover it.

The same guard checks ownership declarations for **all** registry rows,
including framework and frontend tools. In a shared framework compose it checks
only effective test-profile services. It follows one-level `extends`, local
mount/environment overrides and declared same-file service anchors (including
the framework database template); an unreadable form refuses with file, service
and this rule's address. Canonical cluster parts are read in the context of the
test service that extends them. Standalone, developer-local and production
services keep their existing boundary. This guard checks declarations; runtime
runs prove ownership, including a NUL-safe comparison of root-owned paths before
and after a full run. A smaller total count cannot hide a newly root-owned path.

