#!/usr/bin/env python3
"""
cluster.py - the multi-node daemon-cluster e2e harness (HIL-185), shared by every demo that
has a cluster stand: the controller of the stand and its scenario matrix in one program. The
stand is not remembered here - it is read from the compose file named on the command line,
afresh on every command (stand.py says what is read and from where).

Usage: cluster.py <compose-file> <command> [args]   (the compose file relative to the current
directory; a demo calls this from its composer scripts)

  up                   build every image, then start the nodes (they roll the schema out
                       under a claim in the database, HIL-1228) and the cli container.
  down [--volumes]     stop and remove the stack (add --volumes to wipe the DB)
  restart              down, then up
  status               docker ps for the stack + a one-line inspect per node
  inspect <node>       print a node's test:cluster:inspect JSON
  inspect-local <node> the same, asked from inside the node's own container
  client <node> <cmd> [args]  run a test:cluster:client:* command on a node
  entry-upgrade [<master>]  upgrade through the stand entry (scenario 33, HIL-1304)
  entry-welcome <master> [<token>] [<pass>]  welcome verdict through the stand entry (scenario 34, HIL-1305)
  direct-upgrade <node>    upgrade directly on a node (scenario 33, HIL-1304)
  entry-hold <master> up|down  keep or close a socket through the entry (scenario 33, HIL-1304)
  kill <node>          docker kill -9 a node container (simulate node-down)
  start <node>         (re)start a node container, reusing it as it is
  recreate <node>      replace a node with a pristine container
  crash-daemon <node>  SIGKILL the daemon inside a live node container
  kill-worker <node> <i>  SIGKILL worker #i inside a live node container, its daemon kept
  container-id <node>  print a node's docker container id
  container-log <node> print a node's container log so far (docker logs, no follow)
  partition <node>     disconnect a node from the cluster network
  heal <node>          reconnect a node to the cluster network (static IP)
  logs <node>          follow a node's container logs
  stranger up|down     start / remove the stand's stranger, a node certified by an authority
                       the cluster does not trust (HIL-1034, scenario 17)
  own-directory <node> on|off
                       recreate a node with an empty copy of the stand's cluster directory
                       of its own / back on the stand's (scenario 29, HIL-1243)
  db-sql <statement> [<member>]
                       run one SQL statement in the stand's database, or on one member of a
                       clustered one (scenario 26, HIL-1230), and print its rows (scenario 22
                       reads and replaces the database marker, HIL-1206)
  db-kill <member>     SIGKILL one member of a clustered database (scenarios 27 and 28, HIL-1231)
  db-start <member>    start that member's existing container again
  db-proxy             print the state of every server behind the stand's database proxy
  scenarios [n ...]    run the scenario matrix on a fresh stand: the stand's scenarios, or
                       the ones named, which must be the stand's
  e2e [-- Playwright args]  run the cluster browser suite on a fresh stand, with a live
                       phase, then the spec-requested slave loss and after-loss phase

Exit code: 0 done (a green matrix), 1 failed (a red matrix), 2 the stand or the call refused.
"""

import sys

# The harness runs from the repository: keep the interpreter's bytecode cache out of the tree.
sys.dont_write_bytecode = True

import control  # noqa: E402 - after the bytecode switch, which has to precede the import
import browser  # noqa: E402
import scenarios  # noqa: E402
from stand import StandRefused, load_stand  # noqa: E402

REFUSED = 2

COMMANDS = ("up", "down", "restart", "status", "inspect", "inspect-local", "client",
            "entry-upgrade", "entry-welcome", "direct-upgrade", "entry-hold",
            "kill", "start", "recreate", "crash-daemon", "kill-worker", "container-id", "container-log",
            "partition", "heal", "logs", "stranger", "own-directory", "db-sql", "db-kill", "db-start",
            "db-proxy", "scenarios", "e2e")


def check_registry(stand, shown):
    """Every scenario the stand names is one the harness has; `shown` names the compose file."""
    known = {scenario.number for scenario in scenarios.SCENARIOS}
    for number in stand.scenarios:
        if number not in known:
            raise StandRefused(f"{shown}: x-hilos-cluster.scenarios names {number}, "
                               "which the harness does not have")


def run_scenarios(stand, args, prog):
    """The matrix on a FRESH stand, after the named scenarios are checked against its shape."""
    for arg in args:
        if not arg.isdigit() or int(arg) not in stand.scenarios:
            raise StandRefused(f"scenario {arg} is not one of this stand's: "
                               f"{', '.join(str(n) for n in stand.scenarios)}")
    numbers = [int(arg) for arg in args] or stand.scenarios
    for scenario in scenarios.SCENARIOS:
        if scenario.number in numbers:
            lack = scenarios.unmet_need(stand, scenario)
            if lack is not None:
                raise StandRefused(f"{stand.project} cannot carry scenario {scenario.name}: {lack}")

    # A FRESH stack, not merely a running one. The matrix used to leave the fleet dead behind it -
    # scenario 9 recreates a data-plane container faster than the failover grace, and its agents
    # never came back while the leader went on reporting them `started` (P-152) - so a second run
    # over the same stack read that lie and failed the RT scenarios, the only ones that need agents
    # actually running rather than a topology that converged. Since HIL-719 the fleet survives a
    # recreate, and scenario 16 asserts it does. The stack is still replaced every time, because
    # reusing it was never right on its own terms: nothing survives this matrix anyway, since it
    # kills, partitions and recreates every node it touches. The image build behind `up` is a cache
    # hit, so the price is one container recreate.
    #
    # `--volumes` on top of that since HIL-712: the schema of a stand is created by the
    # mariadb image from MYSQL_DATABASE, and an image only does that on the FIRST
    # boot of its data directory. A data directory left over from a run with a different schema
    # name would therefore never get one, and the nodes would not find the database
    # they are configured for. Wiping it also keeps the settings row scenario 11
    # writes from surviving into the next matrix, and it is what gives scenario 21 its
    # empty database: the nodes starting together roll the schema out once (HIL-1228).
    code = control.down(stand, volumes=True)
    if code == 0:
        code = control.up(stand, prog)
    if code != 0:
        print(f"cluster: the stand did not come up (exit {code}); no scenario ran", file=sys.stderr)
        return 1
    return scenarios.run_matrix(stand, numbers)


def run_e2e(stand, args, prog):
    """Refuse missing browser prerequisites before touching the stand, then run both phases."""
    if stand.e2e is None:
        raise StandRefused(f"{stand.compose}: no e2e block in x-hilos-cluster")
    bundle = stand.demo_dir / "frontend" / "dist" / "index.html"
    if not bundle.exists():
        raise StandRefused(f"e2e: no frontend bundle at {bundle} - build it: composer run test:e2e-build")
    code = control.down(stand, volumes=True)
    if code == 0:
        code = control.up(stand, prog, clean_logs=True)
    if code == 0:
        code = control.up_e2e(stand)
    if code != 0:
        print(f"cluster e2e: the stand did not come up (exit {code}); no browser ran", file=sys.stderr)
        return 1
    return browser.run(stand, args)


def dispatch(stand, command, args, prog):
    if command == "up":
        return control.up(stand, prog)
    if command == "down":
        if args not in ([], ["--volumes"]):
            raise StandRefused("usage: cluster down [--volumes]")
        return control.down(stand, volumes=bool(args))
    if command == "restart":
        return control.restart(stand, prog)
    if command == "status":
        return control.status(stand)
    if command == "logs":
        return control.logs(stand, args[0] if args else "")
    if command == "scenarios":
        return run_scenarios(stand, args, prog)
    if command == "e2e":
        return run_e2e(stand, args, prog)
    outcome = control.execute(stand, command, *args)
    sys.stdout.write(outcome.out)
    sys.stderr.write(outcome.err)
    return 0 if outcome.code == 0 else 1


def main(argv):
    # Line by line, so a runner streaming this into its log sees each line when it is said, in
    # order with what the docker commands print between them.
    sys.stdout.reconfigure(line_buffering=True)
    if len(argv) < 3 or argv[2] not in COMMANDS:
        print(f"cluster: usage: {argv[0]} <compose-file> {{{'|'.join(COMMANDS)}}} [args]", file=sys.stderr)
        return REFUSED
    compose, command, args = argv[1], argv[2], argv[3:]
    try:
        stand = load_stand(compose)
        check_registry(stand, compose)
        return dispatch(stand, command, args, f"{argv[0]} {compose}")
    except StandRefused as refusal:
        print(f"cluster: {refusal}", file=sys.stderr)
        return REFUSED


if __name__ == "__main__":
    sys.exit(main(sys.argv))
