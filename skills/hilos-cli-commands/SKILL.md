---
name: hilos-cli-commands
description: Place a Hilos CLI command — which process does its work (the daemon does it, the CLI process initiates and prints), which side of the framework/project line the file lives on, and the site plus reason when it departs from that rule. Use when adding or changing a CLI command, deciding whether a command belongs to the framework or to one project, deciding between doing work in the command and driving an agent over the command channel, writing state from a CLI process that a running daemon also writes, wording what a command prints when the daemon does not answer, or reviewing a command whose body reaches the database or the filesystem directly.
---

# Hilos CLI Command Placement

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then read the
canonical rule before writing or moving command code.

## Read First

- The rule, the four sites, and the guard: `docs/agents/cli/command-execution.md`
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
   `CommandExecution::daemon()`.
4. Wire the answering half on the owner: add the wire name to its `AGENT_COMMANDS`, branch
   in `onSignalCommand()`, and reply exactly once on every path, success and refusal alike.
5. Only if the work genuinely cannot happen in the daemon, declare a departure — and write
   the reason as the factory argument, not as a comment beside it. A departure whose reason
   is blank fails `CommandExecutionRoleTest`.
6. A `cli-offline-write` command must be reachable in the environments that run it: the CLI
   container needs `HILOS_DAEMON_HOST` and `COMMAND_PORT`, because the gate in front of it
   is fail-closed and refuses when it cannot check.
7. For a test-only command, declare it test-only too — `extends TestOnlyCommand`, the
   `test:` prefix on the wire name, and `AgentCommandConfigKey::TEST_ONLY` in the agent
   entry. Extending `AbstractCommandChannelTestCommand` gives the round-trip, the prefix
   latch, and the `daemon` site in one.

## Hard Rules

- Do not write state a running daemon owns from a CLI process. If a command already does,
  move the write to the owner rather than adding a check beside it.
- Do not word a channel failure in a command. `printChannelFailure()` is the only place
  either sentence is written; a command prints its own success and nothing else.
- Do not copy the round-trip into a command. Five commands each carried their own with a
  budget nobody had decided on, and they disagreed.
- Do not read a command's site off its class hierarchy. `CliManager::executions()` asks the
  registry, because reading it any other way needs Reflection, which this project forbids
  (HIL-538).
- Do not give a command to a project because that project is the only one running it
  today. What decides is the state it touches; naming one demo's directory made fifteen
  commands unreachable to every other project until HIL-729 moved them back.
- A temporary departure names the ticket that ends it, in the reason itself.
