"""
stand.py - what the cluster harness knows about the stand it is run against.

Nothing here is written down twice. The nodes of a stand - their ids, containers, addresses,
roles, room and log directories - are read out of the services of its compose file, which is
where the nodes themselves read them from (the CLUSTER_* environment); a second copy would be
one more place to forget when a node is added. What compose cannot say is named in one block
of the same file:

  x-hilos-cluster:
    cli: <the service whose container `docker exec` sends commands to the nodes through>
    stranger: <CLUSTER_NODE_ID of the node under a profile a scenario raises; optional>
    scenarios: [<numbers of the scenarios this stand carries>]

The database the nodes share is found the way the tooling finds it on every stand: the service
labelled `hilos.role: database`, with the credentials its image is started with (MYSQL_USER,
MYSQL_PASSWORD, MYSQL_DATABASE). A scenario sends SQL there through `db-sql` (control.py).

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
# What the database image of a stand is started with, and what db-sql signs in with.
DATABASE_ENV = ("MYSQL_USER", "MYSQL_PASSWORD", "MYSQL_DATABASE")
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


@dataclass(frozen=True)
class Stand:
    """A cluster stand: its compose project, the nodes it starts, and what it carries."""
    compose: Path
    project: str
    network: str
    cli_service: str
    cli_container: str
    cli_profiles: tuple
    # The nodes that come up with the stand, by id.
    members: dict
    masters: list
    slaves: list
    # The node under a profile a scenario raises on its own, or None.
    stranger: Node | None
    scenarios: list
    slave_work_grace_sec: float
    # The service labelled as the stand's database, or None when the stand labels none.
    database: Database | None

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
    return Stand(
        compose=compose,
        project=project,
        network=network,
        cli_service=cli,
        cli_container=cli_container,
        cli_profiles=tuple(services[cli].get("profiles") or ()),
        members=members,
        masters=[node_id for node_id, node in members.items() if node.role == ROLE_MASTER],
        slaves=[node_id for node_id, node in members.items() if node.role == ROLE_SLAVE],
        stranger=stranger,
        scenarios=_scenarios(shown, block.get("scenarios")),
        slave_work_grace_sec=_slave_work_grace_sec(shown, services, members),
        database=_database(shown, project, services),
    )


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
    service = labelled[0]
    spec = services[service]
    env = spec.get("environment") or {}
    missing = [key for key in DATABASE_ENV if not env.get(key)]
    if missing:
        raise StandRefused(f"{shown}: database service {service} has no {', '.join(missing)}")
    return Database(
        service=service,
        container=spec.get("container_name") or f"{project}-{service}-1",
        user=env["MYSQL_USER"],
        password=env["MYSQL_PASSWORD"],
        name=env["MYSQL_DATABASE"],
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
