---
name: hilos-cli-commands
description: Place a Hilos CLI command — which process does its work (the daemon does it, the CLI process initiates and prints), which side of the framework/project line the file lives on, and the site plus reason when it departs from that rule. Use when adding or changing a CLI command, declaring the options a command reads, deciding whether a command belongs to the framework or to one project, deciding between doing work in the command and driving an agent over the command channel, writing state from a CLI process that a running daemon also writes, wording what a command prints when the daemon does not answer, reviewing a command whose body reaches the database or the filesystem directly, or considering forgetting loaded ORM state through CLI, direct API, or a helper.
---

# Hilos CLI Command Placement

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then read the
canonical rule before writing or moving command code.

## Read First

- Before considering ORM forget by CLI, API, or helper:
  [Forgetting loaded ORM state](../../docs/agents/testing.md#forgetting-loaded-orm-state).
- The rule, the five sites (including source generation), and the guard: `docs/agents/cli/command-execution.md`
- The command catalog and how a project registers its own: `docs/agents/cli/commands.md`
- The transport a `daemon` command rides: `docs/agents/architecture/command-server.md`
- Where an agent answers a command: `docs/agents/architecture/agent-lifecycle.md`
- Keeping the answering side light: `docs/agents/antipatterns/heavy-work-in-master.md`

## Workflow

1. Name the owner of the state the command touches — an agent, the master, the database
   the daemon holds connections to. That owner does the work; the command asks it to.
2. Place the file by the owner you just named: a command over state the framework owns
   lives in `framework/backend/Core/CLI/Commands/`, and only a command over something one
   project alone has belongs to that project. Since HIL-729 no demo has one — chat's
   fifteen were all of the first kind — so the project seam
   (`CliManager::registerProjectCommands()`) survives as an empty override in
   `ChatCliManager`, demonstrated rather than used.
3. Write the command as a CLI half: parse the operator's arguments into a payload, send it
   with `CommandChannelClientTrait::sendCommand()`, render the reply. Declare
   `CommandExecution::daemon()`. Print the SUCCESS yourself, on stdout; hand a refusal to
   `printRefusal()` and a failed round-trip to `printChannelFailure()`, both of which word
   it, write it to stderr and answer `ExitCode::ERROR`.
4. Declare in `OPTIONS` every option that `execute()` or its helpers and parents
   read. See `docs/agents/cli/commands.md`.
5. Wire the answering half on the owner: add the wire name to its `AGENT_COMMANDS`, branch
   in `onSignalCommand()`, and reply exactly once on every path, success and refusal alike.
   Three of those paths are no longer yours: since HIL-730 the framework itself refuses a
   command no agent owns, one whose agent nothing placed, and one whose node has no live
   link, and it turns an `AgentException` out of your handler into a reply too. What is
   left to you is the refusal only your handler knows about — and the one silence nothing
   can see for you, a handler that returns having neither thrown nor replied.
6. Only if the work genuinely cannot happen in the daemon, declare a departure — and write
   the reason as the factory argument, not as a comment beside it. A departure whose reason
   is blank fails `CommandExecutionRoleTest`.
7. A `cli-offline-write` command must be reachable in the environments that run it: the CLI
   container needs `HILOS_DAEMON_HOST` and `COMMAND_PORT`, because the gate in front of it
   is fail-closed and refuses when it cannot check.
   For versioned source generation, follow the `cli-source-write` boundary in
   `docs/agents/cli/command-execution.md`; the installation-state gate remains unchanged.
8. For a test-only command, name it `test:*` — the prefix is the whole declaration, and
   `extends TestOnlyCommand` is what has to match it. `TestOnlyNameContractTest` fails
   either way round. Extending `AbstractCommandChannelTestCommand` gives the round-trip, the
   prefix latch, and the `daemon` site in one.

## Hard Rules

- Outside tests, do not use or propose ORM forget for approval; use it only
  when the owner explicitly asks. This covers CLI, direct API, and indirect use;
  follow the linked canon.
- Do not write state a running daemon owns from a CLI process. If a command already does,
  move the write to the owner rather than adding a check beside it.
- Do not word a channel failure or a refusal in a command. `channelFailureText()` and
  `refusalText()` are the only places those sentences are written; a command prints its own
  success and nothing else. A command that knows a FACT beyond the refusal writes it as a
  second line under the shared one, in the same stream — it does not get a parameter on the
  shared printer.
- Do not copy the round-trip into a command. Five commands each carried their own with a
  budget nobody had decided on, and they disagreed.
- Do not read a command's site off its class hierarchy. `CliManager::executions()` asks the
  registry, because reading it any other way needs Reflection, which this project forbids
  (HIL-538).
- Do not give a command to a project because that project is the only one running it
  today. What decides is the state it touches; naming one demo's directory made fifteen
  commands unreachable to every other project until HIL-729 moved them back.
- A temporary departure names the ticket that ends it, in the reason itself.
- Do not read an option the command has not declared in `OPTIONS`. Read it by
  name (a constant or a literal), not through a variable, and do not hand
  `$options` to another class. `CLI-OPTIONS-DECLARED` judges this.
