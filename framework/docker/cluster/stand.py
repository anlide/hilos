"""
stand.py - what the cluster harness knows about the stand it is run against.

Nothing here is written down twice. The nodes of a stand - their ids, containers, addresses,
roles, room and log directories - are read out of the services of its compose file, which is
where the nodes themselves read them from (the CLUSTER_* environment); a second copy would be
one more place to forget when a node is added. What compose cannot say is named in one block
of the same file:

  x-hilos-cluster:
    cli: <the service whose container `docker exec` sends commands to the nodes through>
    entry: <the service of the stand's one browser entry; optional>
    stranger: <CLUSTER_NODE_ID of the node under a profile a scenario raises; optional>
    cluster-directory: {name: <the directory's name in $fs>, path: <its path inside a node's container>}
      optional: a cluster directory of the stand's project, which scenario 29 gives one node a
      copy of its own and scenario 30 reads
    scenarios: [<numbers of the scenarios this stand carries>]
    e2e: {runner: <the Playwright service, under profile e2e>} (optional; needs entry)

The database the nodes share is found the way the tooling finds it on every stand: the service
labelled `hilos.role: database`, with the credentials its image is started with (MYSQL_USER,
MYSQL_PASSWORD, MYSQL_DATABASE). A scenario sends SQL there through `db-sql` (control.py).

A stand whose database is a cluster labels every member `hilos.database.member: "true"` and
exactly one of them `hilos.role: database`; the nodes reach the members through one address the
stand provides (online-testing: a proxy), never a member directly. Scenario 26 asks every member.

The file is read whole through `docker compose config` as JSON rather than parsed as YAML:
the host has no YAML parser, and compose resolves the anchors, the relative paths and the
defaults on its way out, so what is read here is what compose itself would run.

A stand that does not say what the harness needs is refused before anything is done to its
containers (StandRefused), in one line naming the service and what it lacks.
"""

import json
import subprocess
from dataclasses import dataclass
from pathlib import Path

# The top-level key of the block in the stand's compose file.
BLOCK = "x-hilos-cluster"
# Where a node writes its logs inside its container: its daemon log is daemon.log there, because
# DAEMON_LOG_FILE in the demo's .env.example says so.
LOG_TARGET = "/var/log/hilos"
DAEMON_LOG = "daemon.log"
# The label a stand puts on the service of its database, and the value that says so; the same
# label every demo stand carries for the tooling that asks a stand for its database.
DATABASE_LABEL = "hilos.role"
DATABASE_ROLE = "database"
# The label every member of a clustered database carries; exactly one of them also carries
# DATABASE_LABEL.
DATABASE_MEMBER_LABEL = "hilos.database.member"
DATABASE_MEMBER = "true"
# What the database image of a stand is started with, and what db-sql signs in with.
DATABASE_ENV = ("MYSQL_USER", "MYSQL_PASSWORD", "MYSQL_DATABASE")
# Where a database service keeps its data inside its container.
DATABASE_DATA_DIR = "/var/lib/mysql"
ROLE_MASTER = "master"
ROLE_SLAVE = "slave"
# The grace a slave keeps its work for when no node of the stand sets CLUSTER_SLAVE_WORK_GRACE_MS;
# mirrors the framework's default for it (framework/backend/Environment/EnvCatalogStub.php).
DEFAULT_SLAVE_WORK_GRACE_MS = 6000


class StandRefused(Exception):
    """The stand, or the call, is not one the harness can drive; said before any docker action."""


@dataclass(frozen=True)
class Node:
    """One node of the stand, as its compose service says it."""
    id: str
    service: str
    container: str
    ip: str
    role: str
    # Ram the node declares in CLUSTER_NODE_CAPABILITIES (`ram=N`), or None when it declares none.
    ram: int | None
    # The host directory mounted at LOG_TARGET inside the node's container.
    log_dir: Path
    # The compose profile the node is started under, or None for a member of the stand.
    profile: str | None

    @property
    def log_file(self):
        return self.log_dir / DAEMON_LOG


@dataclass(frozen=True)
class Database:
    """The database service of the stand, as its compose service says it."""
    service: str
    container: str
    user: str
    password: str
    name: str
    # The host directory bind-mounted at DATABASE_DATA_DIR; None for a named volume.
    data_dir: str | None = None
    # The service image used by the container that wipes its data directory.
    image: str = ""


@dataclass(frozen=True)
class ClusterDirectory:
    """One cluster directory of the stand's project, as its $fs names it."""
    name: str
    path: str


@dataclass(frozen=True)
class Entry:
    """One browser entry shared by the masters of a stand."""
    service: str
    container: str
    ip: str


@dataclass(frozen=True)
class E2e:
    """The optional browser profile of a cluster stand."""
    runner_service: str
    runner_container: str
    # Profile services to raise before Playwright; the runner is a one-shot container.
    services: tuple


@dataclass(frozen=True)
class Stand:
    """A cluster stand: its compose project, the nodes it starts, and what it carries."""
    compose: Path
    project: str
    network: str
    cli_service: str
    cli_container: str
    cli_profiles: tuple
    # One browser entry shared by the masters, or None on a headless stand.
    entry: Entry | None
    # The nodes that come up with the stand, by id.
    members: dict
    masters: list
    slaves: list
    # The node under a profile a scenario raises on its own, or None.
    stranger: Node | None
    # The stand's cluster directory that scenarios may inspect or give one node a copy of its own.
    cluster_directory: ClusterDirectory | None
    scenarios: list
    slave_work_grace_sec: float
    # The service labelled as the stand's database, or None when the stand labels none.
    database: Database | None
    # The Database of every member of a clustered database, by service name; empty on a stand
    # whose database is one server.
    database_members: tuple
    e2e: E2e | None = None

    @property
    def database_servers(self):
        """Every server of the stand's database, or none when the stand labels no database."""
        return self.database_members or ((self.database,) if self.database is not None else ())

    @property
    def demo_dir(self):
        """The demo the stand belongs to: the parent of the directory holding its compose file."""
        return self.compose.parent.parent

    def member(self, node_id):
        """A member of the stand by id, refused as an unknown node otherwise."""
        node = self.members.get(node_id)
        if node is None:
            raise StandRefused(f"unknown node '{node_id}' (expected one of: {' '.join(self.members)})")
        return node

    def node(self, node_id):
        """A member or the stranger by id: the nodes whose logs the harness may read."""
        if self.stranger is not None and node_id == self.stranger.id:
            return self.stranger
        return self.member(node_id)


def load_stand(compose_path):
    """Read the stand out of its compose file, or raise StandRefused naming what is missing."""
    shown = str(compose_path)
    compose = Path(compose_path).resolve()
    proc = subprocess.run(
        ["docker", "compose", "-f", str(compose), "--profile", "*", "config", "--format", "json"],
        capture_output=True, text=True)
    if proc.returncode != 0:
        said = proc.stderr.strip().splitlines()
        raise StandRefused(f"{shown}: docker compose config failed: {said[-1] if said else proc.returncode}")
    config = json.loads(proc.stdout)
    return stand_from_config(shown, compose, config)


def stand_from_config(shown, compose, config):
    """The stand a parsed `docker compose config` describes; `shown` names the file in a refusal."""
    block = config.get(BLOCK)
    if not isinstance(block, dict):
        raise StandRefused(f"{shown}: no {BLOCK} block")
    services = config.get("services") or {}

    cli = block.get("cli")
    if cli not in services:
        raise StandRefused(f"{shown}: {BLOCK}.cli names {cli}, which is not a service")
    project = config.get("name")
    cli_container = services[cli].get("container_name") or f"{project}-{cli}-1"

    nodes = {}
    network_keys = set()
    for service, spec in services.items():
        env = spec.get("environment") or {}
        node_id = env.get("CLUSTER_NODE_ID")
        if env.get("CLUSTER_ENABLED") != "true" or not node_id:
            continue
        node, key = _node(shown, service, spec, node_id)
        if node_id in nodes:
            raise StandRefused(f"{shown}: node id {node_id} is carried by services "
                               f"{nodes[node_id].service} and {service}")
        nodes[node_id] = node
        network_keys.add(key)
    if len(network_keys) > 1:
        raise StandRefused(f"{shown}: node services sit on more than one network: "
                           f"{', '.join(sorted(network_keys))}")
    network = (config.get("networks") or {}).get(next(iter(network_keys)), {}).get("name") \
        if network_keys else None

    stranger = None
    if block.get("stranger") is not None:
        stranger = nodes.get(str(block["stranger"]))
        if stranger is None or stranger.profile is None:
            raise StandRefused(f"{shown}: {BLOCK}.stranger names {block['stranger']}, "
                               "which is not a node under a profile")

    members = {node_id: node for node_id, node in sorted(nodes.items()) if node.profile is None}
    entry = _entry(shown, block, services, network_keys)
    database = _database(shown, project, services)
    return Stand(
        compose=compose,
        project=project,
        network=network,
        cli_service=cli,
        cli_container=cli_container,
        cli_profiles=tuple(services[cli].get("profiles") or ()),
        entry=entry,
        members=members,
        masters=[node_id for node_id, node in members.items() if node.role == ROLE_MASTER],
        slaves=[node_id for node_id, node in members.items() if node.role == ROLE_SLAVE],
        stranger=stranger,
        cluster_directory=_cluster_directory(shown, block),
        scenarios=_scenarios(shown, block.get("scenarios")),
        slave_work_grace_sec=_slave_work_grace_sec(shown, services, members),
        database=database,
        database_members=_database_members(shown, project, services, database),
        e2e=_e2e(shown, project, block.get("e2e"), services, entry),
    )


def _e2e(shown, project, block, services, entry):
    """Read the optional browser profile, refusing any service the stand cannot start."""
    if block is None:
        return None
    if not isinstance(block, dict):
        raise StandRefused(f"{shown}: {BLOCK}.e2e must name a runner")
    runner = block.get("runner")
    if not isinstance(runner, str) or runner not in services:
        raise StandRefused(f"{shown}: {BLOCK}.e2e.runner names {runner}, which is not a service")
    if "e2e" not in (services[runner].get("profiles") or []):
        raise StandRefused(f"{shown}: e2e runner {runner} is not under profile e2e")
    if entry is None:
        raise StandRefused(f"{shown}: {BLOCK}.e2e needs {BLOCK}.entry - the browser's way onto the stand")
    profile_services = tuple(service for service, spec in services.items()
                             if service != runner and "e2e" in (spec.get("profiles") or []))
    return E2e(runner, services[runner].get("container_name") or f"{project}-{runner}-1",
               profile_services)


def _entry(shown, block, services, network_keys):
    """Resolve the optional browser entry on the same network as the cluster nodes."""
    service = block.get("entry")
    if service is None:
        return None
    spec = services.get(service)
    if spec is None:
        raise StandRefused(f"{shown}: {BLOCK}.entry names {service}, which is not a service")
    container = spec.get("container_name")
    if not container:
        _lacks(shown, service, "container_name")
    addresses = {key: value.get("ipv4_address") for key, value in (spec.get("networks") or {}).items()
                 if isinstance(value, dict) and value.get("ipv4_address")}
    if len(addresses) != 1 or next(iter(addresses)) not in network_keys:
        raise StandRefused(f"{shown}: {BLOCK}.entry must have one address on the node network")
    return Entry(service, container, next(iter(addresses.values())))


def _node(shown, service, spec, node_id):
    """One node service read into a Node, with the key of the network its address is on."""
    env = spec.get("environment") or {}
    container = spec.get("container_name")
    if not container:
        _lacks(shown, service, "container_name")
    addressed = {key: net["ipv4_address"] for key, net in (spec.get("networks") or {}).items()
                 if isinstance(net, dict) and net.get("ipv4_address")}
    if len(addressed) != 1:
        if len(addressed) > 1:
            raise StandRefused(f"{shown}: node services sit on more than one network: "
                               f"{', '.join(sorted(addressed))}")
        _lacks(shown, service, "address on the node network")
    key, ip = next(iter(addressed.items()))
    role = env.get("CLUSTER_NODE_ROLE")
    if role not in (ROLE_MASTER, ROLE_SLAVE):
        _lacks(shown, service, "CLUSTER_NODE_ROLE master or slave")
    log_dir = next((volume.get("source") for volume in spec.get("volumes") or []
                    if isinstance(volume, dict) and volume.get("type") == "bind"
                    and volume.get("target") == LOG_TARGET), None)
    if not log_dir:
        _lacks(shown, service, f"volume for {LOG_TARGET}")
    profiles = spec.get("profiles") or []
    return Node(
        id=node_id,
        service=service,
        container=container,
        ip=ip,
        role=role,
        ram=_ram(env.get("CLUSTER_NODE_CAPABILITIES")),
        log_dir=Path(log_dir),
        profile=profiles[0] if profiles else None,
    ), key


def _database(shown, project, services):
    """The service the stand labels as its database, or None when it labels none."""
    labelled = [service for service, spec in services.items()
                if (spec.get("labels") or {}).get(DATABASE_LABEL) == DATABASE_ROLE]
    if not labelled:
        return None
    if len(labelled) > 1:
        raise StandRefused(f"{shown}: services {', '.join(labelled)} are all labelled "
                           f"{DATABASE_LABEL}: {DATABASE_ROLE}")
    return _database_of(shown, project, services, labelled[0], "database service")


def _cluster_directory(shown, block):
    """The optional cluster directory the stand names, with a path inside a node."""
    if "cluster-directory" not in block:
        return None
    spec = block["cluster-directory"]
    if not isinstance(spec, dict) or not isinstance(spec.get("name"), str) \
            or not spec["name"] or not isinstance(spec.get("path"), str) or not spec["path"]:
        raise StandRefused(f"{shown}: {BLOCK}.cluster-directory needs a name and a path")
    path = spec["path"]
    if not path.startswith("/"):
        raise StandRefused(f"{shown}: {BLOCK}.cluster-directory.path {path} is not absolute")
    return ClusterDirectory(spec["name"], path)


def _database_members(shown, project, services, database):
    """The members of a clustered database, by service name; empty when the stand labels none.

    The service labelled as the database must be one of them: it is the member the tooling
    reaches, and a database apart from its own cluster would be a second database.
    """
    labelled = sorted(service for service, spec in services.items()
                      if (spec.get("labels") or {}).get(DATABASE_MEMBER_LABEL) == DATABASE_MEMBER)
    if not labelled:
        return ()
    if database is None or database.service not in labelled:
        name = database.service if database is not None else "(none)"
        raise StandRefused(f"{shown}: the database service {name} is not one of the database members: "
                           f"{', '.join(labelled)}")
    return tuple(_database_of(shown, project, services, service, "database member service")
                 for service in labelled)


def _database_of(shown, project, services, service, what):
    """One database service read into a Database; `what` names it in a refusal."""
    spec = services[service]
    env = spec.get("environment") or {}
    missing = [key for key in DATABASE_ENV if not env.get(key)]
    if missing:
        raise StandRefused(f"{shown}: {what} {service} has no {', '.join(missing)}")
    data_dir = next((volume.get("source") for volume in spec.get("volumes") or []
                     if isinstance(volume, dict) and volume.get("type") == "bind"
                     and volume.get("target") == DATABASE_DATA_DIR), None)
    return Database(
        service=service,
        container=spec.get("container_name") or f"{project}-{service}-1",
        user=env["MYSQL_USER"],
        password=env["MYSQL_PASSWORD"],
        name=env["MYSQL_DATABASE"],
        data_dir=data_dir,
        image=spec.get("image") or "",
    )


def _lacks(shown, service, what):
    raise StandRefused(f"{shown}: node service {service} has no {what}")


def _ram(capabilities):
    """N of `ram=N` among a node's declared capabilities, or None when it declares no ram."""
    for capability in (capabilities or "").split(","):
        name, _, value = capability.strip().partition("=")
        if name == "ram" and value.isdigit():
            return int(value)
    return None


def _scenarios(shown, named):
    """The scenario numbers the block names, refused when empty or when one is named twice.

    Whether the harness has a scenario of that number is not asked here - the stand is read
    without the scenarios module; cluster.py asks it against the registry.
    """
    if not named:
        raise StandRefused(f"{shown}: {BLOCK}.scenarios is empty")
    if not isinstance(named, list):
        raise StandRefused(f"{shown}: {BLOCK}.scenarios names {named}, which the harness does not have")
    numbers = []
    for number in named:
        if not isinstance(number, int) or isinstance(number, bool):
            raise StandRefused(f"{shown}: {BLOCK}.scenarios names {number}, which the harness does not have")
        if number in numbers:
            raise StandRefused(f"{shown}: {BLOCK}.scenarios names {number} twice")
        numbers.append(number)
    return numbers


def _slave_work_grace_sec(shown, services, members):
    """The slave work grace the members set, in seconds; one value, or the stand is refused."""
    graces = {}
    for node_id, node in members.items():
        value = (services[node.service].get("environment") or {}).get("CLUSTER_SLAVE_WORK_GRACE_MS")
        if value not in (None, ""):
            graces[node_id] = value
    if len(set(graces.values())) > 1:
        said = ", ".join(f"{node_id}={value}" for node_id, value in graces.items())
        raise StandRefused(f"{shown}: nodes disagree on CLUSTER_SLAVE_WORK_GRACE_MS: {said}")
    grace_ms = next(iter(graces.values()), DEFAULT_SLAVE_WORK_GRACE_MS)
    return int(grace_ms) / 1000
