"""
control.py - the host-side controller of a cluster stand (HIL-185), for any stand the harness
reads (stand.py). Preview-style: a thin orchestrator over `docker compose` plus the six fault
switches the scenarios need - `docker kill -9` (node-down / failover),
`docker network disconnect` (partition / split-brain), a SIGKILL of the daemon or one worker
inside a live container (crash recovery / partial failure), a SIGSTOP of one worker (a hung
orphan, scenario 9), and a blackhole route between an island of nodes and the rest of the stand
(a partition that keeps the island linked within itself, scenario 36, HIL-1287) - and one lever
on the stand's database, `db-sql` (a node reading another database marker, HIL-1206; each
member of a clustered database asked, HIL-1230). It also recreates a node with an empty copy of
the stand's cluster directory of its own: the directory guard refuses it (HIL-1242/HIL-1243).
Assertions live in the scenario matrix (scenarios.py), which reads each node's
`test:cluster:inspect` reply and compares it against the expected invariants. It can also
recreate a node with one environment variable of its own (scenario 32, HIL-1274).

A command that the scenarios drive answers with an Outcome - its exit code, what it would print,
and what it would complain - and prints nothing itself: the matrix reads the answer, and
cluster.py prints it for a person. The commands only a person runs (up, down, status, logs)
print as they go.

Ordinary up builds and recreates the default services in one Compose call; up, down and restart
preserve saved protected mode, and only down with volumes wipes the database. Test startup opens
recognized freezes through existing owner commands before fleet readiness. Browser-test log
cleanup runs separately with nodes stopped, preserving every freeze store and .gitkeep.
"""

import csv
import json
import os
import pathlib
import re
import shlex
import subprocess
import sys
import tempfile
import time
from collections import namedtuple

from stand import StandRefused, resolved_config

Outcome = namedtuple("Outcome", "code out err")

# Where the application sits inside a node's container and the cli container.
CLI_ENTRY = ["php", "backend/Bootstrap/cli.php"]
# The helper that steps into a node's network namespace to lay or lift a blackhole route - busybox
# `ip` does it, and the node images carry no ip tool (HIL-1287).
NETNS_IMAGE = "alpine:3.24.2"


def _run(args, merge_stderr=False, input_text=None, timeout=None):
    """Run a command for its outcome, capturing both streams (or one merged stream)."""
    proc = subprocess.run(args, input=input_text, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.STDOUT if merge_stderr else subprocess.PIPE,
                          timeout=timeout)
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


def _test_log_directories(stand):
    """Node log directories as the CLI container sees them, validated before any cleanup.

    The cli container mounts the demo at /app and nothing else of the host, so a node whose log
    directory is not strictly inside the demo is refused before anything is built or removed.
    """
    demo = stand.demo_dir.resolve()
    paths = []
    for node in _logged_nodes(stand):
        try:
            inside = node.log_dir.resolve().relative_to(demo)
        except ValueError:
            raise StandRefused(f"{stand.project}: node {node.id} logs to {node.log_dir}, outside "
                               f"{stand.demo_dir}, which the cli container cannot reach") from None
        if not inside.parts:
            raise StandRefused(f"{stand.project}: node {node.id} logs to the demo root")
        paths.append(f"/app/{inside.as_posix()}")
    return paths


def clear_test_logs(stand):
    """Clear old browser-test logs inside a fresh CLI image, with every node stopped.

    Keep .gitkeep and every protected-mode.state.json* file, including unfinished stores.
    Validate all paths before deleting any file; find never follows symlinks out of a log tree.
    """
    directories = _test_log_directories(stand)
    running = compose(stand, "--profile", "*", "ps", "--status", "running", "--services")
    if running.code != 0:
        return running.code
    live = set(running.out.splitlines()) & {node.service for node in _logged_nodes(stand)}
    if live:
        raise StandRefused(f"test log cleanup needs stopped nodes: {', '.join(sorted(live))}")
    script = '''set -eu
for directory do
    if [ -e "$directory" ] && [ ! -d "$directory" ]; then
        echo "test log path is not a directory: $directory" >&2; exit 1
    fi
    parent=$directory
    while [ "$parent" != / ]; do
        if [ -L "$parent" ]; then
            echo "test log path crosses a symlink: $directory" >&2; exit 1
        fi
        parent=$(dirname "$parent")
    done
done
mkdir -p "$@"
find "$@" -depth -mindepth 1 ! -type d ! -name .gitkeep ! -name 'protected-mode.state.json*' -delete
find "$@" -depth -mindepth 1 -type d ! -name .gitkeep ! -name 'protected-mode.state.json*' -empty -delete
'''
    return compose(stand, *_cli_profiles(stand), "run", "--build", "--rm", "--no-deps",
                   stand.cli_service, "sh", "-c", script, "cluster-e2e-log-reset",
                   *directories, capture=False)


def up(stand, prog):
    """Build and recreate the default Compose services, retaining database and freeze stores."""
    print(f"cluster: starting {len(stand.members)} nodes and the cli container...", flush=True)
    code = compose(stand, "up", "-d", "--build", "--force-recreate", capture=False)
    if code != 0:
        return code
    print("cluster: up. Give the mesh a few seconds to elect a leader, then:")
    print(f"  {prog} status      # see roster + leader per node")
    print(f"  {prog} scenarios   # run the assertion matrix")
    return 0


def down(stand, volumes=False):
    """Stop the stand; `volumes` also wipes its database after every server stops.

    Compose removes named volumes. Host-memory directories need a container of the database's
    own image: its files belong to the database user, which the host cannot remove directly.
    """
    code = compose(stand, "--profile", "*", "down", *(["-v"] if volumes else []), capture=False)
    if code != 0 or not volumes:
        return code
    for server in stand.database_servers:
        if server.data_dir is None or not os.path.isdir(server.data_dir):
            continue
        outcome = _run(["docker", "run", "--rm", "-v", f"{server.data_dir}:/data",
                        "--entrypoint", "find", server.image, "/data", "-mindepth", "1", "-delete"])
        if outcome.code != 0:
            error = next((line for line in reversed(outcome.err.splitlines()) if line.strip()), str(outcome.code))
            print(f"cluster: could not wipe {server.data_dir}: {error}", file=sys.stderr)
            return 1
        print(f"cluster: wiped {server.data_dir}", flush=True)
    return 0


def up_e2e(stand):
    """Raise the browser profile services after the cluster members and entry are running."""
    return compose(stand, "--profile", "e2e", "up", "-d", "--build", *stand.e2e.services, capture=False)


def run_e2e_runner(stand, env, *argv):
    """Run one Playwright-side command with the cluster browser environment."""
    flags = [part for key, value in env.items() for part in ("-e", f"{key}={value}")]
    return compose(stand, "--profile", "e2e", "run", "--rm", *flags,
                   stand.e2e.runner_service, *argv, capture=False)


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


def client(stand, node_id, *args, timeout=None):
    """Run a CLI command against a node, optionally bounded by a subprocess timeout.

    A scenario opens no browser, so one is attached through test client commands rather than
    by a socket, and addressed/fan-out signals are raised the same way (HIL-668). Test startup
    also uses this channel for protected-mode inspection and the existing owner's open command.
    """
    node = stand.member(node_id)
    return _run(["docker", "exec", "-e", f"HILOS_DAEMON_HOST={node.ip}", stand.cli_container,
                 *CLI_ENTRY, *args], timeout=timeout)


def ensure_open_for_tests(stand, timeout):
    """Open a saved, recognized freeze through its owner before waiting for the test fleet.

    Inspect every master, including while agents are stopped. Only unreachable command channels
    are polled during startup; refusals and malformed replies stop the run. The shared deadline
    bounds subprocesses too. A successful drive still owes an inactive snapshot on every master.
    """
    deadline = time.monotonic() + timeout
    snapshots = {}

    def refuse(node_id, reason):
        snapshot = snapshots.get(node_id, {})
        raise StandRefused(f"test startup: node={node_id} phase={snapshot.get('phase', 'unknown')!r} "
                           f"operation={snapshot.get('operation', 'unknown')!r}: {reason}")

    def call(node_id, command, context):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            refuse(context, f"timeout waiting for {command} on {node_id}")
        try:
            return client(stand, node_id, command, timeout=remaining)
        except subprocess.TimeoutExpired:
            refuse(context, f"timeout running {command} on {node_id}")

    def pause(node_id):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            refuse(node_id, "timeout waiting for all masters to become inactive")
        time.sleep(min(1.0, remaining))  # scenarios.POLL_INTERVAL

    def snapshot(node_id, *, wait_channel=False, context=None):
        context = node_id if context is None else context
        while True:
            outcome = call(node_id, "protected-mode:inspect", context)
            if outcome.code == 0:
                break
            # CommandChannelClientTrait distinguishes an absent channel from a refusal or a
            # daemon that heard the request but never answered. Retry only the absent channel.
            if wait_channel and outcome.err.strip().startswith("Cannot reach the daemon command channel at "):
                pause(context)
                continue
            refuse(context, f"protected-mode:inspect on {node_id} failed: {outcome.err or outcome.out}")
        try:
            data = json.loads(outcome.out)
        except json.JSONDecodeError:
            refuse(context, f"malformed protected-mode:inspect JSON on {node_id}")
        if not isinstance(data, dict):
            refuse(context, f"malformed protected-mode:inspect snapshot on {node_id}")
        snapshots[node_id] = data
        fields = ("rtMounted", "phase", "operation", "initiatorAgentType",
                  "initiatorAgentIndex", "initiatorNodeId")
        if any(field not in data for field in fields) or data.get("rtMounted") is not True:
            refuse(context, f"missing protected-mode snapshot fields or runtime on {node_id}")
        if data["phase"] not in ("inactive", "activating", "active", "verifying", "deactivating"):
            refuse(context, f"invalid protected-mode phase on {node_id}")
        if data["phase"] == "inactive":
            if any(data[field] is not None for field in fields[2:]):
                refuse(context, f"inactive snapshot retains an operation or initiator on {node_id}")
        else:
            opener(node_id)
        return data

    def opener(node_id):
        data = snapshots[node_id]
        operation = data["operation"]
        owner = data["initiatorAgentType"]
        initiator = data["initiatorNodeId"]
        # Both existing carriers are singletons. A different index, even of a recognized type,
        # does not identify the instance that owns these commands.
        if (not isinstance(operation, str) or not operation
                or data["initiatorAgentIndex"] is not None
                or not isinstance(initiator, str) or initiator not in stand.members):
            refuse(node_id, "unknown operation or initiator identity/node")
        if owner == "hilos_index":
            # ProtectedModeTestDriverTrait accepts any nonempty operation except manual
            # maintenance. Identity, rather than an operation-name prefix, identifies its freeze.
            command = ("maintenance:disable" if operation == "manual_maintenance"
                       else "test:protected-mode:open")
        elif owner == "hilos_backup" and operation == "restore":
            command = "protected-mode:open"
        else:
            refuse(node_id, f"unknown protected-mode owner/operation: {owner!r}")
        return initiator, command

    for node_id in stand.masters:
        snapshot(node_id, wait_channel=True)
    frozen = [node_id for node_id in stand.masters if snapshots[node_id]["phase"] != "inactive"]
    if not frozen:
        return 0
    source = frozen[0]
    identity_fields = ("operation", "initiatorAgentType", "initiatorAgentIndex", "initiatorNodeId")
    identity = tuple(snapshots[source][field] for field in identity_fields)
    for node_id in frozen:
        if tuple(snapshots[node_id][field] for field in identity_fields) != identity:
            refuse(node_id, "masters disagree about the operation or initiator")
    initiator, command = opener(source)
    # A freeze may originate on a slave. Reach its command channel too, without waiting for
    # fleet placement (which a full freeze deliberately prevents).
    current = snapshot(initiator, wait_channel=True, context=source)
    if initiator in stand.masters and (current["phase"] == "inactive"
            or tuple(current[field] for field in identity_fields) != identity):
        refuse(initiator, "initiator snapshot changed before the open command")
    print(f"cluster: opening saved protected mode via {command} on {initiator}", flush=True)
    outcome = call(initiator, command, source)
    if outcome.code != 0:
        refuse(source, f"{command} on {initiator} failed: {outcome.err or outcome.out}")
    while True:
        for node_id in stand.masters:
            snapshot(node_id)
        pending = [node_id for node_id in stand.masters if snapshots[node_id]["phase"] != "inactive"]
        if not pending:
            return 0
        pause(pending[0])


def _upgrade_curl(url, cookie=None, seconds=3):
    """The browser upgrade request shared by the one-shot and held-socket commands."""
    args = ["curl", "--http1.1", "-k", "-sS", "-D", "-", "-o", "/dev/null", "--max-time", str(seconds),
            "-H", "Connection: Upgrade", "-H", "Upgrade: websocket",
            "-H", "Sec-WebSocket-Version: 13", "-H", "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ=="]
    if cookie is not None:
        args.extend(["-H", f"Cookie: hilos_stand_master={cookie}"])
    return [*args, url]


def _upgrade_status(raw):
    """The HTTP status and stand upstream address from curl's response headers."""
    statuses = re.findall(r"^HTTP/1\.[01] (\d{3})", raw, re.M)
    upstream = re.findall(r"^X-Hilos-Stand-Master:\s*([^\r\n]+)", raw, re.I | re.M)
    return (int(statuses[-1]) if statuses else None,
            upstream[-1].strip() if upstream else None)


def entry_upgrade(stand, master=None):
    """Upgrade through the shared TLS entry, reporting status and responding master."""
    if stand.entry is None:
        raise StandRefused(f"{stand.project} has no browser entry (x-hilos-cluster.entry)")
    outcome = _run(["docker", "exec", stand.cli_container,
                    *_upgrade_curl(f"https://{stand.entry.ip}/ws", master)])
    status, upstream = _upgrade_status(outcome.out)
    if status is None:
        return Outcome(outcome.code or 1, "", outcome.err or "entry upgrade gave no HTTP response")
    node = next((node_id for node_id in stand.masters
                 if upstream and upstream.startswith(stand.member(node_id).ip + ":")), "-")
    return Outcome(0, f"{status} {node}\n", "")


# Read the 101 and its first, unmasked WebSocket text frame inside the stand's CLI container.
# PHP is already in that image; this uses the same TLS entry and cookie as a browser without
# adding a one-off script to the mounted repository (HIL-1305).
ENTRY_WELCOME_PHP = r'''
$entry = $argv[1];
$master = $argv[2];
$cookieName = $argv[3];
$token = $argv[4];
$pass = $argv[5];
$context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$socket = stream_socket_client("tls://{$entry}:443", $number, $error, 5, STREAM_CLIENT_CONNECT, $context);
if ($socket === false) { fwrite(STDERR, "entry welcome: {$error}\n"); exit(1); }
stream_set_timeout($socket, 5);
$path = '/ws' . ($pass === '' ? '' : '?hilosPass=' . rawurlencode($pass));
$cookies = 'hilos_stand_master=' . $master . ($token === '' ? '' : '; ' . $cookieName . '=' . $token);
$request = "GET {$path} HTTP/1.1\r\nHost: {$entry}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
    . "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nCookie: {$cookies}\r\n\r\n";
fwrite($socket, $request);
$raw = '';
while (($boundary = strpos($raw, "\r\n\r\n")) === false) {
    $piece = fread($socket, 4096);
    if ($piece === false || $piece === '') { fwrite(STDERR, "entry welcome: no 101 response\n"); exit(1); }
    $raw .= $piece;
}
$headers = substr($raw, 0, $boundary);
$buffer = substr($raw, $boundary + 4);
if (!preg_match('/^HTTP\/1\.[01] 101/m', $headers)) {
    fwrite(STDERR, "entry welcome: upgrade refused: " . strtok($headers, "\r\n") . "\n"); exit(1);
}
$read = static function (int $length) use ($socket, &$buffer): string {
    while (strlen($buffer) < $length) {
        $piece = fread($socket, $length - strlen($buffer));
        if ($piece === false || $piece === '') { fwrite(STDERR, "entry welcome: incomplete frame\n"); exit(1); }
        $buffer .= $piece;
    }
    $part = substr($buffer, 0, $length);
    $buffer = substr($buffer, $length);
    return $part;
};
$first = ord($read(1));
$second = ord($read(1));
if (($first & 15) !== 1 || ($second & 128) !== 0) {
    fwrite(STDERR, "entry welcome: first frame is not unmasked text\n"); exit(1);
}
$length = $second & 127;
if ($length === 126) { $length = unpack('n', $read(2))[1]; }
if ($length === 127) {
    $words = unpack('N2', $read(8));
    $length = $words[1] * 4294967296 + $words[2];
}
$frame = json_decode($read($length), true);
if (!is_array($frame) || ($frame['type'] ?? null) !== 'handshake'
    || !is_array($frame['data'] ?? null) || !is_array($frame['data']['protectedMode'] ?? null)) {
    fwrite(STDERR, "entry welcome: malformed handshake frame\n"); exit(1);
}
$cookieName = $frame['data']['sessionCookieName'] ?? null;
if (!is_string($cookieName) || $cookieName === '') {
    fwrite(STDERR, "entry welcome: no session cookie name\n"); exit(1);
}
if ($token === '') {
    $pattern = '/^Set-Cookie:\s*' . preg_quote($cookieName, '/') . '=([^;\r\n]+)/im';
    if (!preg_match($pattern, $headers, $match)) {
        fwrite(STDERR, "entry welcome: no session cookie\n"); exit(1);
    }
    $token = $match[1];
}
preg_match('/^X-Hilos-Stand-Master:\s*([^\r\n]+)/im', $headers, $upstream);
echo json_encode(['active' => $frame['data']['protectedMode']['active'] ?? null,
    'cookieName' => $cookieName, 'token' => $token, 'upstream' => $upstream[1] ?? '']);
'''

_session_cookie_names = {}


def entry_welcome(stand, master, token=None, pass_code=None):
    """Read the real 101 welcome through the entry and report this master's verdict (HIL-1305)."""
    if stand.entry is None:
        raise StandRefused(f"{stand.project} has no browser entry (x-hilos-cluster.entry)")
    if master not in stand.masters:
        raise StandRefused("usage: cluster entry-welcome <master> [<session-token>] [<pass>]")
    cookie_name = _session_cookie_names.get(stand.project, "")
    if token is not None and not cookie_name:
        probe = entry_welcome(stand, master)
        if probe.code != 0:
            return probe
        cookie_name = _session_cookie_names[stand.project]
    answer = _run(["docker", "exec", stand.cli_container, "php", "-r", ENTRY_WELCOME_PHP,
                   stand.entry.ip, master, cookie_name, token or "", pass_code or ""])
    if answer.code != 0:
        return Outcome(answer.code, "", answer.err or "entry welcome failed")
    try:
        data = json.loads(answer.out)
    except json.JSONDecodeError:
        return Outcome(1, "", f"entry welcome gave malformed JSON: {answer.out!r}")
    responder = next((node_id for node_id in stand.masters
                      if data.get("upstream", "").startswith(stand.member(node_id).ip + ":")), None)
    if responder != master or not isinstance(data.get("active"), bool) or not data.get("token"):
        return Outcome(1, "", f"entry welcome from {master} gave an invalid verdict: {data!r}")
    _session_cookie_names[stand.project] = data["cookieName"]
    return Outcome(0, f"{master} {'stub' if data['active'] else 'inside'} {data['token']}\n", "")


def direct_upgrade(stand, node_id):
    """Try a node's WebSocket port directly, reporting its status or refusal."""
    node = stand.member(node_id)
    outcome = _run(["docker", "exec", stand.cli_container,
                    *_upgrade_curl(f"http://{node.ip}:8092/ws")])
    status, _ = _upgrade_status(outcome.out)
    if status is None and outcome.code == 7:
        return Outcome(0, "refused\n", "")
    if status is None:
        return Outcome(outcome.code or 1, "", outcome.err or "direct upgrade gave no HTTP response")
    return Outcome(0, f"{status}\n", "")


def entry_hold(stand, master, action):
    """Keep one upgraded socket on a named master until the scenario closes it."""
    if stand.entry is None:
        raise StandRefused(f"{stand.project} has no browser entry (x-hilos-cluster.entry)")
    if master not in stand.masters or action not in ("up", "down"):
        raise StandRefused("usage: cluster entry-hold <master> {up|down}")
    pid_file = "/tmp/hilos-entry-hold.pid"
    if action == "down":
        script = (f'if [ -f {pid_file} ]; then read hold < {pid_file}; '
                  f'kill "$hold" 2>/dev/null || true; rm -f {pid_file}; fi')
        return _run(["docker", "exec", stand.cli_container, "sh", "-c", script])
    curl = _upgrade_curl(f"https://{stand.entry.ip}/ws", master, seconds=600)
    script = (shlex.join(curl) + f" > /tmp/hilos-entry-hold.out 2>&1 & "
              f'hold=$!; echo "$hold" > {pid_file}; wait "$hold"')
    return _run(["docker", "exec", "-d", stand.cli_container, "sh", "-c", script])


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

# A stopped process neither leaves on its own nor obeys SIGTERM (the signal stays
# pending) and keeps the daemon's inherited listening sockets — the hung orphan
# OrphanReaper exists for (scenario 9, HIL-1310). The first worker met is taken:
# worker indexes start at 1 and shift after a respawn, and the scenario does not
# name one.
FREEZE_WORKER_PHP = r'''
$self = getmypid();
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
    foreach ($arguments as $argument) {
        $isWorker = $isWorker || str_ends_with($argument, "Bootstrap/worker.php");
    }
    if (!$isWorker) {
        continue;
    }
    $pid = $candidate;
    break;
}
if ($pid === null) {
    fwrite(STDERR, "no worker found" . PHP_EOL);
    exit(1);
}
posix_kill($pid, SIGSTOP);
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


def freeze_worker(stand, node_id):
    node = stand.member(node_id)
    outcome = _run(["docker", "exec", node.container,
                    "php", "-d", "display_errors=stderr", "-r", FREEZE_WORKER_PHP])
    if outcome.code != 0:
        return outcome._replace(out="", err=outcome.err + f"cluster: no worker to freeze inside {node.container}\n")
    return Outcome(0, f"cluster: SIGSTOPped worker pid {outcome.out.strip()} inside {node.container}\n", outcome.err)


# These identities belong to the host harness, never to the daemon. A worker index
# alone is reusable; container id + pid + /proc start ticks identify one process.
WorkerProcess = namedtuple("WorkerProcess", "node container index pid start_ticks")

WORKER_PROCESS_PHP = r'''
$index = getenv("HILOS_TEST_WORKER_INDEX");
$pid = getenv("HILOS_TEST_WORKER_PID");
$signal = getenv("HILOS_TEST_WORKER_SIGNAL");
$files = $pid === false ? (glob("/proc/[0-9]*/cmdline") ?: []) : ["/proc/" . $pid . "/cmdline"];
foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }
    $arguments = explode("\0", file_get_contents($file));
    $worker = false;
    foreach ($arguments as $argument) {
        $worker = $worker || str_ends_with($argument, "Bootstrap/worker.php");
    }
    if (!$worker || !in_array("--worker-id=" . $index, $arguments, true)) {
        continue;
    }
    $candidate = (int)basename(dirname($file));
    $stat = file_get_contents("/proc/" . $candidate . "/stat");
    $fields = explode(" ", substr($stat, strrpos($stat, ")") + 2));
    $birth = $fields[19];
    if ($pid !== false && $birth !== getenv("HILOS_TEST_WORKER_BIRTH")) {
        continue;
    }
    if ($signal !== false && !posix_kill($candidate, (int)$signal)) {
        fwrite(STDERR, "could not signal captured worker\n");
        exit(1);
    }
    echo json_encode(["pid" => $candidate, "startTicks" => $birth, "state" => $fields[0]]), "\n";
    exit(0);
}
echo "null\n";
'''


def worker_process(stand, node_id, index):
    """Match a current placement's logged worker to a live process, not a stale log alone."""
    node = stand.member(node_id)
    container = container_id(stand, node_id).out.strip()
    outcome = _run(["docker", "exec", "-e", f"HILOS_TEST_WORKER_INDEX={index}", node.container,
                    "php", "-d", "display_errors=stderr", "-r", WORKER_PROCESS_PHP])
    assert outcome.code == 0, f"cannot inspect worker #{index} on {node_id}: {outcome}"
    row = json.loads(outcome.out)
    assert container and row, f"no live worker #{index} on {node_id}: {outcome}"
    return WorkerProcess(node_id, container, index, row["pid"], row["startTicks"])


def signal_worker_process(stand, process, signal=None):
    """Signal only the captured incarnation; a dead/replaced process answers `null`.

    Callers capture BEFORE STOP and arrange CONT in finally. Checking the container
    prevents a pid/start-tick coincidence after container recreation from matching.
    """
    node = stand.member(process.node)
    current = _run(["docker", "inspect", "-f", "{{.Id}} {{.State.Running}}", node.container])
    if current.out.strip() != f"{process.container} true":
        # A stopped container has no live processes, even when its id is retained.
        return Outcome(0, "null\n", "")
    flags = ["-e", f"HILOS_TEST_WORKER_INDEX={process.index}",
             "-e", f"HILOS_TEST_WORKER_PID={process.pid}",
             "-e", f"HILOS_TEST_WORKER_BIRTH={process.start_ticks}"]
    if signal is not None:
        flags.extend(["-e", f"HILOS_TEST_WORKER_SIGNAL={signal}"])
    return _run(["docker", "exec", *flags, node.container,
                 "php", "-d", "display_errors=stderr", "-r", WORKER_PROCESS_PHP])


def partition(stand, node_id):
    node = stand.member(node_id)
    return _said(_run(["docker", "network", "disconnect", stand.network, node.container]),
                 f"cluster: partitioned {node.container}")


def heal(stand, node_id):
    node = stand.member(node_id)
    return _said(_run(["docker", "network", "connect", "--ip", node.ip, stand.network, node.container]),
                 f"cluster: reconnected {node.container}")


def _blackhole(node, verb, ips):
    """Lay (`add`) or lift (`del`) a blackhole route to each address in one node's network
    namespace, from a throwaway container that shares it. A route to lift that is not there is no
    error: a node recreated meanwhile has none left."""
    if verb == "add":
        script = " && ".join(f"ip route add blackhole {ip}/32" for ip in ips)
    else:
        script = "; ".join(f"ip route del blackhole {ip}/32 2>/dev/null" for ip in ips) + "; true"
    return _run(["docker", "run", "--rm", "--network", f"container:{node.container}", "--cap-add", "NET_ADMIN",
                 NETNS_IMAGE, "sh", "-c", script])


def _island(stand, node_ids, command):
    """The nodes named as an island and the members of the stand left outside it.

    Refused when the island names no node or leaves none outside it."""
    island = [stand.member(node_id) for node_id in node_ids]
    rest = [node for node_id, node in stand.members.items() if node_id not in node_ids]
    if not island or not rest:
        raise StandRefused(f"usage: cluster {command} <node>... — the island must leave some node outside it")
    return island, rest


def _route_island(island, rest, verb, failed):
    """Lay or lift the blackhole routes on both sides of an island: in each node of the island to
    every node of the rest, and in each node of the rest back to the island. Every node is visited
    even after one refuses, so that lifting leaves no route behind; the first refusal answers."""
    sides = [*((node, rest) for node in island), *((node, island) for node in rest)]
    refused = None
    for node, others in sides:
        outcome = _blackhole(node, verb, [other.ip for other in others])
        if outcome.code != 0 and refused is None:
            refused = outcome._replace(out="", err=outcome.err + f"cluster: could not {failed} {node.container}\n")
    return refused


def cut(stand, *node_ids):
    """Cut an island of nodes off the rest of the stand together.

    An island that keeps its links within itself, to the database and to the cli container - the
    partition `partition` cannot make, since it takes a node off the network whole (HIL-1287). So
    a node of the island is asked through the cli container as usual."""
    island, rest = _island(stand, node_ids, "cut")
    refused = _route_island(island, rest, "add", "cut")
    if refused is not None:
        return refused
    return Outcome(0, f"cluster: cut {', '.join(node.id for node in island)} "
                      f"off {', '.join(node.id for node in rest)}\n", "")


def mend(stand, *node_ids):
    """Lift a cut from both sides; a node recreated meanwhile has no route left to lift."""
    island, rest = _island(stand, node_ids, "mend")
    refused = _route_island(island, rest, "del", "mend")
    if refused is not None:
        return refused
    return Outcome(0, f"cluster: mended {', '.join(node.id for node in island)} "
                      f"with {', '.join(node.id for node in rest)}\n", "")


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
        return compose(stand, "--profile", node.profile, "up", "-d", "--build", "--no-deps", node.service)
    if action == "down":
        return compose(stand, "--profile", node.profile, "rm", "-sf", node.service)
    raise StandRefused("usage: cluster stranger {up|down}")


def _recreate_with_override(stand, node, override):
    """Replace a node using a resolved JSON base and a compose override passed on stdin.

    Flatten includes before removing the container so imported services can be overridden.
    Compose may otherwise keep an existing mount when its type changes (own-directory).
    """
    config = resolved_config(stand.compose)
    base = tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", prefix="hilos-cluster-",
                                       suffix=".json", dir="/tmp", delete=False)
    try:
        with base:
            json.dump(config, base)
        removed = compose(stand, "rm", "-sf", node.service)
        if removed.code != 0:
            return removed
        return _run(["docker", "compose", "-f", base.name, "-f", "-", "up", "-d",
                     "--force-recreate", node.service], input_text=json.dumps(override))
    finally:
        pathlib.Path(base.name).unlink()


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
        return _said(_recreate_with_override(stand, node, override),
                     f"cluster: recreated {node.container} with a {name} directory of its own")
    if action == "off":
        removed = compose(stand, "rm", "-sf", node.service)
        if removed.code != 0:
            return removed
        return _said(compose(stand, "up", "-d", "--force-recreate", node.service),
                     f"cluster: recreated {node.container} on the stand's {name} directory")
    raise StandRefused("usage: cluster own-directory <node> {on|off}")


def own_env(stand, node_id, assignment=None):
    """Recreate one member with one environment value of its own, or return to the stand's."""
    node = stand.member(node_id)
    if assignment == "off":
        return _said(compose(stand, "up", "-d", "--force-recreate", node.service),
                     f"cluster: recreated {node.container} on the stand's environment")
    if assignment is not None and "=" in assignment:
        name, value = assignment.split("=", 1)
        if name:
            override = {"services": {node.service: {"environment": {name: value}}}}
            return _said(_recreate_with_override(stand, node, override),
                         f"cluster: recreated {node.container} with {name}={value} of its own")
    raise StandRefused("usage: cluster own-env <node> {NAME=value|off}")


def cluster_directory_exec(stand, node_id, *argv):
    """Run a command in one node's cluster directory for scenario assertions and cleanup."""
    if stand.cluster_directory is None:
        raise StandRefused(f"{stand.project} names no cluster directory (x-hilos-cluster.cluster-directory)")
    node = stand.member(node_id)
    return _run(["docker", "exec", "-w", stand.cluster_directory.path, node.container, *argv])


def _database_member(stand, member):
    """A member named by service, with one refusal shared by SQL and lifecycle switches."""
    database = next((m for m in stand.database_members if m.service == member), None)
    if database is None:
        raise StandRefused(f"unknown database member '{member}' (expected one of: "
                           f"{' '.join(m.service for m in stand.database_members)})")
    return database


def db_kill(stand, member):
    """SIGKILL one database member, leaving its container and data for db-start."""
    database = _database_member(stand, member)
    return _said(_run(["docker", "kill", database.container]),
                 f"cluster: killed database member {database.container}")


def db_start(stand, member):
    """Start the existing member container again, as the stand's compose declares it."""
    database = _database_member(stand, member)
    return _said(compose(stand, "up", "-d", database.service),
                 f"cluster: started database member {database.container}")


def node_started_at(stand, node):
    """Docker's start timestamp, which changes even when the container id does not."""
    return _run(["docker", "inspect", "-f", "{{.State.StartedAt}}", stand.member(node).container])


def db_proxy(stand):
    """Report the proxy's named backend servers and their states from HAProxy's CSV page."""
    if stand.database_proxy is None:
        raise StandRefused(f"{stand.project} labels no service as its database proxy (hilos.database.proxy)")
    outcome = _run(["docker", "exec", stand.database_proxy, "wget", "-qO-",
                    "http://127.0.0.1:8404/stats;csv"])
    if outcome.code != 0:
        return outcome
    rows = list(csv.reader(outcome.out.splitlines()))
    if not rows:
        return Outcome(1, "", "proxy returned no server states")
    header = [name.lstrip("# ") for name in rows[0]]
    try:
        server_index = header.index("svname")
        status_index = header.index("status")
    except ValueError:
        return Outcome(1, "", "proxy returned no server status columns")
    states = [(row[server_index], row[status_index]) for row in rows[1:]
              if len(row) > max(server_index, status_index)
              and row[server_index] not in ("FRONTEND", "BACKEND")]
    return Outcome(0, "".join(f"{name}\t{status}\n" for name, status in states), "")


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
        database = _database_member(stand, member)
    return _run(["docker", "exec", database.container, "mariadb", f"-u{database.user}",
                 f"-p{database.password}", "-N", "-B", database.name, "-e", statement])


def db_log(stand, member=None):
    """The container log of one database member so far, without following it.

    Scenario 31 names why a replica stopped from a line only its server log carries; the
    stand is taken down after the scenario (HIL-1229).
    """
    if member is None:
        raise StandRefused("usage: cluster db-log <member>")
    return _run(["docker", "logs", _database_member(stand, member).container], merge_stderr=True)


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
    "freeze-worker": freeze_worker,
    "container-id": container_id,
    "container-log": container_log,
    "partition": partition,
    "heal": heal,
    "cut": cut,
    "mend": mend,
    "entry-upgrade": entry_upgrade,
    "entry-welcome": entry_welcome,
    "direct-upgrade": direct_upgrade,
    "entry-hold": entry_hold,
}


def execute(stand, command, *args):
    """Run one of the commands that answer with an Outcome, by the name cluster.py gives it."""
    if command == "stranger":
        return stranger(stand, *args[:1])
    if command == "own-directory":
        if not args:
            raise StandRefused("usage: cluster own-directory <node> {on|off}")
        return own_directory(stand, *args[:2])
    if command == "own-env":
        if not args:
            raise StandRefused("usage: cluster own-env <node> {NAME=value|off}")
        return own_env(stand, *args[:2])
    if command == "db-sql":
        return db_sql(stand, *args[:2])
    if command == "db-log":
        return db_log(stand, *args[:1])
    if command in ("db-kill", "db-start"):
        if len(args) != 1:
            raise StandRefused(f"usage: cluster {command} <member>")
        return (db_kill if command == "db-kill" else db_start)(stand, args[0])
    if command == "db-proxy":
        if args:
            raise StandRefused("usage: cluster db-proxy")
        return db_proxy(stand)
    if command == "entry-upgrade":
        if len(args) > 1:
            raise StandRefused("usage: cluster entry-upgrade [<master>]")
        return entry_upgrade(stand, *args)
    if command == "entry-welcome":
        if not 1 <= len(args) <= 3:
            raise StandRefused("usage: cluster entry-welcome <master> [<session-token>] [<pass>]")
        return entry_welcome(stand, *args)
    if command == "direct-upgrade":
        if len(args) != 1:
            raise StandRefused("usage: cluster direct-upgrade <node>")
        return direct_upgrade(stand, *args)
    if command == "entry-hold":
        if len(args) != 2:
            raise StandRefused("usage: cluster entry-hold <master> {up|down}")
        return entry_hold(stand, *args)
    if command in ("cut", "mend"):
        return NODE_COMMANDS[command](stand, *args)
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
