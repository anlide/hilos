"""
control.py - the host-side controller of a cluster stand (HIL-185), for any stand the harness
reads (stand.py). Preview-style: a thin orchestrator over `docker compose` plus the four fault
switches the scenarios need - `docker kill -9` (node-down / failover), `docker network
disconnect` (partition / split-brain), and a SIGKILL of the daemon or one worker inside a live
container (crash recovery / partial failure) - and one lever on the stand's database, `db-sql`
(a node reading another database marker, HIL-1206; each member of a clustered database asked,
HIL-1230). It also recreates a node with an empty copy of the stand's cluster directory of its
own: the directory guard refuses it (HIL-1242/HIL-1243). Assertions live in the scenario
matrix (scenarios.py), which reads each node's `test:cluster:inspect` reply and compares it
against the expected invariants.

A command that the scenarios drive answers with an Outcome - its exit code, what it would print,
and what it would complain - and prints nothing itself: the matrix reads the answer, and
cluster.py prints it for a person. The commands only a person runs (up, down, status, logs)
print as they go.
"""

import json
import os
import subprocess
from collections import namedtuple

from stand import StandRefused

Outcome = namedtuple("Outcome", "code out err")

# Where the application sits inside a node's container and the cli container.
CLI_ENTRY = ["php", "backend/Bootstrap/cli.php"]


def _run(args, merge_stderr=False, input_text=None):
    """Run a command for its outcome, capturing both streams (or one merged stream)."""
    proc = subprocess.run(args, input=input_text, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.STDOUT if merge_stderr else subprocess.PIPE)
    return Outcome(proc.returncode, proc.stdout or "", proc.stderr or "")


def _said(outcome, line):
    """The outcome of a switch that succeeded quietly, with the line saying what it did."""
    if outcome.code != 0:
        return outcome._replace(out="")
    return Outcome(0, line + "\n", outcome.err)


def compose(stand, *args, capture=True):
    """`docker compose` on the stand's file: an Outcome when captured, the exit code otherwise."""
    command = ["docker", "compose", "-f", str(stand.compose), *args]
    if capture:
        return _run(command)
    return subprocess.run(command).returncode


def _cli_profiles(stand):
    return [flag for profile in stand.cli_profiles for flag in ("--profile", profile)]


# ------------------------------------------------------------------ stand lifecycle

def _logged_nodes(stand):
    """Every node of the stand that writes logs: the members, and the stranger when there is one."""
    return [*stand.members.values(), *([stand.stranger] if stand.stranger is not None else [])]


def ensure_env(stand):
    """The demo's env files from their examples, and where the nodes' log directories go, before a start.

    The parent of each node's log directory, not the directory itself: docker creates that one on
    the node's first start, owned by root like everything the daemon writes into it.
    """
    demo = stand.demo_dir
    for env in (demo / ".env", demo / "tests" / ".env"):
        example = env.with_name(".env.example")
        if not env.exists() and example.exists():
            env.write_bytes(example.read_bytes())
    for node in _logged_nodes(stand):
        node.log_dir.parent.mkdir(parents=True, exist_ok=True)


def _freeze_files(stand):
    """The freeze files a node's last run may have left, as the cli container sees them.

    The cli container mounts the demo at /app and nothing else of the host, so a node whose log
    directory is not inside the demo is refused here, before anything is built or started.
    """
    demo = stand.demo_dir.resolve()
    paths = []
    for node in _logged_nodes(stand):
        try:
            inside = node.log_dir.resolve().relative_to(demo)
        except ValueError:
            raise StandRefused(f"{stand.project}: node {node.id} logs to {node.log_dir}, outside "
                               f"{stand.demo_dir}, which the cli container cannot reach") from None
        paths.append(f"/app/{inside.as_posix()}/protected-mode.state.json*")
    return paths


def up(stand, prog):
    """Build every image, clear the last run's freezes, start the members and the cli container."""
    freeze_files = _freeze_files(stand)
    ensure_env(stand)
    print("cluster: building images...", flush=True)
    # Every profile, so the stranger's image too: a scenario starts it from a finished image rather
    # than building one in the middle of the matrix.
    code = compose(stand, "--profile", "*", "build", capture=False)
    if code != 0:
        return code
    # Clear any freeze the last run left on a node, from INSIDE a container: a node's log directory
    # is owned by root (the daemons run as root in their containers) and unlink asks permission of
    # the DIRECTORY, so an rm from the host cannot reach it. On the up rather than on the teardown
    # for the same reason as the three demos (HOTFIX d5f0ad75): a run killed hard never reaches its
    # own teardown, but it always reaches the next up.
    code = compose(stand, *_cli_profiles(stand), "run", "--rm", stand.cli_service,
                   "sh", "-c", "rm -f " + " ".join(freeze_files), capture=False)
    if code != 0:
        return code
    # The database, and a one-off schema step if the stand still has one, are not named: compose
    # starts whatever the members depend on.
    print(f"cluster: starting {len(stand.members)} nodes and the cli container...", flush=True)
    services = [node.service for node in stand.members.values()]
    code = compose(stand, *_cli_profiles(stand), "up", "-d", *services, stand.cli_service, capture=False)
    if code != 0:
        return code
    print("cluster: up. Give the mesh a few seconds to elect a leader, then:")
    print(f"  {prog} status      # see roster + leader per node")
    print(f"  {prog} scenarios   # run the assertion matrix")
    return 0


def down(stand, volumes=False):
    """Stop and remove every service of the stand; `volumes` wipes the database volume too."""
    return compose(stand, "--profile", "*", "down", *(["-v"] if volumes else []), capture=False)


def restart(stand, prog):
    code = down(stand)
    return code if code != 0 else up(stand, prog)


# ------------------------------------------------------------------ reading the nodes

def inspect(stand, node_id):
    """A node's test:cluster:inspect reply, asked through the cli container."""
    node = stand.member(node_id)
    # The cli container faces every node, so it carries no HILOS_DAEMON_HOST of its own and the
    # node's address is named here, per call.
    return _run(["docker", "exec", "-e", f"HILOS_DAEMON_HOST={node.ip}", stand.cli_container,
                 *CLI_ENTRY, "test:cluster:inspect"])


def inspect_local(stand, node_id):
    """Inspect a node from INSIDE its own container over localhost. Needed when the node is
    network-partitioned (unreachable from the shared cli container and from MySQL):
    test:cluster:inspect is DB-free, so it still answers over the local command socket."""
    node = stand.member(node_id)
    return _run(["docker", "exec", "-e", "APP_ENV=test", node.container,
                 *CLI_ENTRY, "test:cluster:inspect"])


def client(stand, node_id, *args):
    """Run one of the test-only client commands against a node (HIL-668). The cluster demo is
    headless, so a browser is attached through the CLI rather than by a socket, and the
    addressed/fan-out signals are raised the same way -- everything past that point is the
    production path."""
    node = stand.member(node_id)
    return _run(["docker", "exec", "-e", f"HILOS_DAEMON_HOST={node.ip}", stand.cli_container,
                 *CLI_ENTRY, *args])


def _json_reply(raw):
    """The JSON object a CLI reply carries after whatever it printed first, or None."""
    brace = raw.find("{")
    if brace < 0:
        return None
    try:
        obj, _ = json.JSONDecoder().raw_decode(raw[brace:])
        return obj
    except json.JSONDecodeError:
        return None


def _placements(reply):
    return ["%s@%s:%s" % (p["agentId"], p["nodeId"], p["state"]) for p in reply.get("placements", [])]


def status(stand):
    """docker ps for the stand, then a one-line inspect per member."""
    print("== containers ==", flush=True)
    subprocess.run(["docker", "ps", "--filter", f"label=com.docker.compose.project={stand.project}",
                    "--format", "table {{.Names}}\t{{.Status}}"])
    print()
    print("== per-node cluster view ==")
    for node_id in stand.members:
        outcome = inspect(stand, node_id)
        if "{" not in outcome.out:
            print("  %-4s (unreachable)" % node_id)
            continue
        reply = _json_reply(outcome.out)
        if reply is None:
            print("  %-4s (bad reply)" % node_id)
            continue
        if not reply.get("enabled"):
            print("  %-4s cluster disabled" % node_id)
            continue
        print("  %-4s phase=%-26s leader=%-4s term=%s quorum=%s placements=%s" % (
            node_id, reply.get("lifecycleState"), str(reply.get("leaderId")), reply.get("term"),
            reply.get("hasQuorum"), ",".join(_placements(reply)) or "-"))
    return 0


def status_json(stand):
    """The per-node cluster view as JSON for the preview control panel:
    {"nodes":[{node,reachable,enabled,role,phase,leader,term,quorum,placements}]}.
    Empty {"nodes":[]} when the stand is down (one cheap check, no per-node execs).
    One json.dumps over the whole view, so the output is always valid JSON."""
    running = _run(["docker", "inspect", "-f", "{{.State.Running}}", stand.cli_container])
    if running.code != 0 or running.out.strip() != "true":
        return json.dumps({"nodes": []})
    out = []
    for node_id, node in stand.members.items():
        try:
            proc = subprocess.run(
                ["docker", "exec", "-e", "HILOS_DAEMON_HOST=" + node.ip, stand.cli_container,
                 *CLI_ENTRY, "test:cluster:inspect"],
                capture_output=True, text=True, timeout=15)
            reply = _json_reply(proc.stdout)
        except (subprocess.TimeoutExpired, OSError):
            reply = None
        if reply is None:
            out.append({"node": node_id, "reachable": False})
            continue
        if not reply.get("enabled"):
            out.append({"node": node_id, "reachable": True, "enabled": False})
            continue
        out.append({
            "node": node_id, "reachable": True, "enabled": True,
            "role": next((x["role"] for x in reply.get("nodes", []) if x["nodeId"] == node_id), None),
            "phase": reply.get("lifecycleState"), "leader": reply.get("leaderId"),
            "term": reply.get("term"), "quorum": reply.get("hasQuorum"),
            "placements": _placements(reply),
        })
    return json.dumps({"nodes": out})


# ------------------------------------------------------------------ fault switches

def kill(stand, node_id):
    node = stand.member(node_id)
    return _said(_run(["docker", "kill", node.container]), f"cluster: killed {node.container}")


def start(stand, node_id):
    """Restart the EXISTING container instead of recreating it: a node has to come back on its
    own hardware, leftovers and all. A daemon that crashed internally orphans workers that keep
    holding its ports, and the watchdog now sweeps them before the next daemon binds
    (Hilos\\Core\\Daemon\\OrphanReaper) — so `--force-recreate` here would hide exactly what
    scenario 9 guards."""
    node = stand.member(node_id)
    return _said(compose(stand, "up", "-d", node.service), f"cluster: started {node.container}")


def recreate(stand, node_id):
    """Replace a node with a pristine container. For the one case that genuinely needs a fresh
    socket stack — healing a partition, where reconnecting the interface would leave half-open
    sockets on both sides — and never for an ordinary restart."""
    node = stand.member(node_id)
    return _said(compose(stand, "up", "-d", "--force-recreate", node.service),
                 f"cluster: recreated {node.container}")


def container_id(stand, node_id):
    node = stand.member(node_id)
    return _run(["docker", "inspect", "-f", "{{.Id}}", node.container])


def container_log(stand, node_id):
    """The container log as it stands, not followed: scenario 21 reads which node applied the
    schema on startup, a line only the watchdog's stdout carries (HIL-1228)."""
    node = stand.member(node_id)
    return _run(["docker", "logs", node.container], merge_stderr=True)


# Kill the daemon INSIDE a running container, leaving the watchdog (PID 1) and the
# daemon's workers alive: the workers are re-parented onto the watchdog and keep the
# daemon's listening sockets, which is the state a real internal crash produces.
# The lookup runs in PHP because the image carries no procps (no pgrep/pkill) and PHP
# is the one interpreter guaranteed to be there; `display_errors=stderr` keeps a warning
# about a process that exited mid-scan out of the pid on stdout.
# It skips its own pid first, exactly as OrphanReaper::findChildren() does: this script
# is passed with `-r`, so its source — needle included — is its own command line, and
# /proc listed lexicographically puts a two-digit pid ahead of the daemon's one-digit
# one. Without the skip the helper SIGKILLs itself and never reaches the daemon.
CRASH_DAEMON_PHP = r'''
$self = getmypid();
$pid = null;
foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $file) {
    $candidate = (int)basename(dirname($file));
    if ($candidate === $self) {
        continue;
    }
    $cmdline = file_get_contents($file);
    if ($cmdline === false || !str_contains($cmdline, "Bootstrap/daemon.php")) {
        continue;
    }
    $pid = $candidate;
    break;
}
if ($pid === null) {
    fwrite(STDERR, "no daemon process found" . PHP_EOL);
    exit(1);
}
posix_kill($pid, SIGKILL);
echo $pid, PHP_EOL;
'''

KILL_WORKER_PHP = r'''
$self = getmypid();
$workerIndex = getenv("HILOS_KILL_WORKER");
$workerArgument = "--worker-id=" . $workerIndex;
$pid = null;
foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $file) {
    $candidate = (int)basename(dirname($file));
    if ($candidate === $self) {
        continue;
    }
    $cmdline = file_get_contents($file);
    if ($cmdline === false) {
        continue;
    }
    $arguments = explode("\0", $cmdline);
    $isWorker = false;
    $hasIndex = false;
    foreach ($arguments as $argument) {
        $isWorker = $isWorker || str_ends_with($argument, "Bootstrap/worker.php");
        $hasIndex = $hasIndex || $argument === $workerArgument;
    }
    if (!$isWorker || !$hasIndex) {
        continue;
    }
    $pid = $candidate;
    break;
}
if ($pid === null) {
    fwrite(STDERR, "no worker #" . $workerIndex . " found" . PHP_EOL);
    exit(1);
}
posix_kill($pid, SIGKILL);
echo $pid, PHP_EOL;
'''


def crash_daemon(stand, node_id):
    node = stand.member(node_id)
    outcome = _run(["docker", "exec", node.container,
                    "php", "-d", "display_errors=stderr", "-r", CRASH_DAEMON_PHP])
    if outcome.code != 0:
        return outcome._replace(out="", err=outcome.err + f"cluster: no daemon process to kill inside {node.container}\n")
    return Outcome(0, f"cluster: SIGKILLed daemon pid {outcome.out.strip()} inside {node.container}\n", outcome.err)


def kill_worker(stand, node_id, index=None):
    node = stand.member(node_id)
    if index is None or not index.isdigit():
        raise StandRefused("usage: cluster kill-worker <node> <worker-index>")
    outcome = _run(["docker", "exec", "-e", f"HILOS_KILL_WORKER={index}", node.container,
                    "php", "-d", "display_errors=stderr", "-r", KILL_WORKER_PHP])
    if outcome.code != 0:
        return outcome._replace(out="", err=outcome.err + f"cluster: no worker #{index} inside {node.container}\n")
    return Outcome(0, f"cluster: SIGKILLed worker #{index} pid {outcome.out.strip()} inside {node.container}\n",
                   outcome.err)


def partition(stand, node_id):
    node = stand.member(node_id)
    return _said(_run(["docker", "network", "disconnect", stand.network, node.container]),
                 f"cluster: partitioned {node.container}")


def heal(stand, node_id):
    node = stand.member(node_id)
    return _said(_run(["docker", "network", "connect", "--ip", node.ip, stand.network, node.container]),
                 f"cluster: reconnected {node.container}")


def logs(stand, node_id):
    """Follow a node's container logs."""
    return compose(stand, "logs", "-f", stand.member(node_id).service, capture=False)


def stranger(stand, action=None):
    """The stranger of the stand (scenario 17, HIL-1034): up on its own, without touching the
    running cluster; down removes the container outright, so the next matrix starts without it.
    Not a member: kill/start/recreate have nothing to do with it."""
    if stand.stranger is None:
        raise StandRefused(f"{stand.project} has no stranger (x-hilos-cluster.stranger)")
    node = stand.stranger
    if action == "up":
        return compose(stand, "--profile", node.profile, "up", "-d", "--no-deps", node.service)
    if action == "down":
        return compose(stand, "--profile", node.profile, "rm", "-sf", node.service)
    raise StandRefused("usage: cluster stranger {up|down}")


def own_directory(stand, node_id, action=None):
    """Recreate one member on its own empty cluster directory, or restore the shared one."""
    if stand.cluster_directory is None:
        raise StandRefused(f"{stand.project} names no cluster directory (x-hilos-cluster.cluster-directory)")
    node = stand.member(node_id)
    name = stand.cluster_directory.name
    # Compose keeps the existing named mount when `up --force-recreate` changes its type.
    # Remove the old container first so the next one takes the override's actual mount.
    if action == "on":
        # Compose merges volumes by target: long-form tmpfs replaces the shared volume there.
        override = {"services": {node.service: {"volumes": [
            {"type": "tmpfs", "target": stand.cluster_directory.path}]}}}
        removed = compose(stand, "rm", "-sf", node.service)
        if removed.code != 0:
            return removed
        outcome = _run(["docker", "compose", "-f", str(stand.compose), "-f", "-", "up", "-d",
                        "--force-recreate", node.service], input_text=json.dumps(override))
        return _said(outcome, f"cluster: recreated {node.container} with a {name} directory of its own")
    if action == "off":
        removed = compose(stand, "rm", "-sf", node.service)
        if removed.code != 0:
            return removed
        return _said(compose(stand, "up", "-d", "--force-recreate", node.service),
                     f"cluster: recreated {node.container} on the stand's {name} directory")
    raise StandRefused("usage: cluster own-directory <node> {on|off}")


def cluster_directory_exec(stand, node_id, *argv):
    """Run a command in one node's cluster directory for scenario assertions and cleanup."""
    if stand.cluster_directory is None:
        raise StandRefused(f"{stand.project} names no cluster directory (x-hilos-cluster.cluster-directory)")
    node = stand.member(node_id)
    return _run(["docker", "exec", "-w", stand.cluster_directory.path, node.container, *argv])


def db_sql(stand, statement=None, member=None):
    """Run one SQL statement in the stand's database as its application user, for what it prints:
    tab-separated rows without a header (scenario 22 reads and replaces the database marker,
    HIL-1206). The database is a fact of the stand rather than of a node, so the lever lives
    here, beside the other switches, and not in a command of the framework.

    `member` names the service of one member of a clustered database, and the statement runs
    there rather than in the member labelled as the database: scenario 26 asks every member of a
    clustered database (HIL-1230)."""
    if stand.database is None:
        raise StandRefused(f"{stand.project} labels no service as its database (hilos.role: database)")
    if not statement:
        raise StandRefused("usage: cluster db-sql <statement> [<member>]")
    database = stand.database
    if member is not None:
        database = next((m for m in stand.database_members if m.service == member), None)
        if database is None:
            raise StandRefused(f"unknown database member '{member}' (expected one of: "
                               f"{' '.join(m.service for m in stand.database_members)})")
    return _run(["docker", "exec", database.container, "mariadb", f"-u{database.user}",
                 f"-p{database.password}", "-N", "-B", database.name, "-e", statement])


# The node commands, as cluster.py and the scenarios name them: each answers with an Outcome.
NODE_COMMANDS = {
    "inspect": inspect,
    "inspect-local": inspect_local,
    "client": client,
    "kill": kill,
    "start": start,
    "recreate": recreate,
    "crash-daemon": crash_daemon,
    "kill-worker": kill_worker,
    "container-id": container_id,
    "container-log": container_log,
    "partition": partition,
    "heal": heal,
}


def execute(stand, command, *args):
    """Run one of the commands that answer with an Outcome, by the name cluster.py gives it."""
    if command == "stranger":
        return stranger(stand, *args[:1])
    if command == "own-directory":
        if not args:
            raise StandRefused("usage: cluster own-directory <node> {on|off}")
        return own_directory(stand, *args[:2])
    if command == "db-sql":
        return db_sql(stand, *args[:2])
    if not args:
        raise StandRefused(f"unknown node '' (expected one of: {' '.join(stand.members)})")
    return NODE_COMMANDS[command](stand, *args)


# ------------------------------------------------------------------ node logs

def node_log_path(node):
    """Where a node's daemon log lands on the host: the directory the stand bind-mounts out of
    the container's /var/log/hilos (one volume per node), and the daemon writes daemon.log there
    because DAEMON_LOG_FILE in .env.example says so."""
    return node.log_file


def node_log(node, offset=0):
    """The daemon log a node wrote from a byte offset on: its text, or '' when there is no
    file. Read from the host - the files inside the mount are root-owned but world-readable,
    so no sudo and no docker exec are needed. An offset names a byte of ONE file, so it holds
    only while the node does not start between measuring and reading; a node that does is
    read by node_log_mark() instead."""
    try:
        with open(node_log_path(node), "rb") as f:
            f.seek(offset)
            return f.read().decode("utf-8", errors="replace")
    except FileNotFoundError:
        return ""


def node_log_size(node):
    """Byte length of a node's daemon log, or 0 when there is no file yet - an offset into that
    one file, good while the node does not start before it is read; otherwise node_log_mark()."""
    try:
        return node_log_path(node).stat().st_size
    except FileNotFoundError:
        return 0


LogMark = namedtuple("LogMark", "inode size")


def node_log_mark(node):
    """Which file a node's daemon log is right now and how long it is: the mark to read a node
    that STARTS between measuring and reading. An offset cannot do that - starting a container
    starts its watcher, and the watcher moves every *.log of the node to staging/<time>/
    before the daemon writes its first line (DockerManager::rotateLogs()), so the daemon opens
    a fresh file and an offset of the old one reads the new one past whatever it wrote first.
    LogMark(None, 0) when there is no file yet."""
    try:
        st = node_log_path(node).stat()
    except FileNotFoundError:
        return LogMark(None, 0)
    return LogMark(st.st_ino, st.st_size)


def node_log_since(node, mark):
    """What a node's daemon log gained after its mark: the tail past the marked size while the
    file is still the marked one, the whole file once it is another. Before the start rotates
    the log that tail is empty, so a line a previous run left is never read as this run's.
    The inode is compared off the OPEN descriptor, not a second stat of the path, so a
    rotation between the two cannot compare one file and read the other. What it cannot see:
    lines the node added to the OLD file between the mark and its restart stay in staging -
    no scenario needs them. '' when there is no file."""
    try:
        with open(node_log_path(node), "rb") as f:
            same_file = os.fstat(f.fileno()).st_ino == mark.inode
            f.seek(mark.size if same_file else 0)
            return f.read().decode("utf-8", errors="replace")
    except FileNotFoundError:
        return ""
