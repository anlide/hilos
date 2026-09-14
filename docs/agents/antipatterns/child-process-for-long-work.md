# Anti-pattern: A Child Process for Long Work

Read this before moving long or blocking work out of an agent into a separate
process — a child the agent spawns, a background script, a daemon of your own —
or before weighing such an option.

## Core Rule

Long or blocking work in this framework lives in a monopolistic agent.

Do not spawn a child process to carry your own PHP work out of an agent; put the
work in a monopolistic agent, whose worker is its own.

A child process falls outside the framework, and it does so in three ways at
once:

- it has no agent journal — nothing it logs is the agent's;
- it has no place in the cluster — no node holds it, no scope names it;
- it does not stop and restart with the node.

Everyone who reaches for a child process writes those three by hand, and
differently each time.

## Workflow

1. Declare a monopolistic agent for the work
   ([../agent-system/monopolistic-agent.md](../agent-system/monopolistic-agent.md));
   the registration order is
   [../agent-system/adding-agent.md](../agent-system/adding-agent.md).
2. Give it the work whole — not a slice per tick, the whole job.
3. Let a tick take as long as the job takes: the worker belongs to this agent
   alone and nobody waits behind it. `sleep()` stays forbidden — a long tick is
   work, not waiting.

## Preferred Shape

`framework/backend/Log/LogCarrierAgent.php` with its daemon proxy
`framework/backend/Log/LogCarrierAgentDaemon.php` — the carrier that moves
rotated log batches from staging into the archive
([../architecture/logs.md](../architecture/logs.md), *The Archive May Live On
Another Device*). It is the sample for two reasons:

- it is as small as such an agent gets: it talks to nobody, writes no runtime
  state and sends no signal;
- it shows the privilege the shape was chosen for: a copy of any size is made in
  one tick, deliberately not cut into slices, because the worker is its own.

## What the rule cuts by

The rule cuts by **whose work it is**, not by the word `Process`.

- **Forbidden:** your own PHP work moved into a separate process — anything this
  framework can already run inside a worker.
- **Allowed:** an external binary the framework cannot replace. The live cases
  are all in the backup subsystem:
  `framework/backend/Backup/BackupCreator.php` (`runToFile()` — `tar` and the
  database dump), `framework/backend/Backup/BackupRestorer.php` (`runProcess()` —
  unpacking on restore) and `framework/backend/Backup/Agent/BackupAgent.php`
  (`spawnShipStep()` — `rsync` / `scp` shipping an archive off the node).

## Two allowed shapes for an external binary

Both live in the tree; the rule names both so that neither reads as a violation:

1. **Spawned and polled from the tick.** The monopolistic agent starts the
   binary and polls it on later ticks; the tick itself does not block.
   `BackupAgent::spawnShipStep()` starts the transfer, `pollShipping()` and
   `tickShipping()` follow it to its exit or its timeout.
2. **Run to completion under a ceiling.** The agent starts the binary and blocks
   until it exits, is killed or overruns its timeout. Only when an operator asked
   for the work by an explicit command and is owed an answer now:
   `BackupAgent::handleShipCommand()` → `shipStepNow()` → `runToCompletion()`,
   the ceiling read from `BACKUP_SHIP_TIMEOUT`.

The condition both shapes share is the one that makes the second no
contradiction of the first: the worker of a monopolistic agent is its own, and
no client waits behind it — the same privilege the log carrier uses to spend a
whole tick on one copy. In an ordinary worker both shapes are forbidden.

## Anti-Patterns

Two children of `BackupAgent` run the backup's *own* PHP work in a separate
process. They are two different cases, not one exemption:

- **The create child — a debt.** `BackupAgent::startBackup()` spawns
  `backup:run`, and the class docblock justifies it with *never blocking the
  daemon loop*. The rule refutes exactly that justification: a monopolistic agent
  does not block the daemon loop either, because its worker is its own. The shape
  stands as debt, named here; rewriting it is not this document's business.
- **The restore child — a named exception.** `BackupAgent::onProtectedModeReady()`
  spawns `backup:restore-run` once the node is frozen. A process that replaces the
  database under a live node cannot be the process that holds connections to it,
  so this child is not a case of the rule at all. Do not "fix" it toward the rule.

## Exceptions

The process housekeeping of the framework itself is not touched by this rule.
It is not work moved out of an agent; it is how the framework exists as a set of
processes:

- `framework/backend/Socket/Server/WorkerServer.php` (`startWorker()` — forking
  a worker);
- `framework/backend/Core/Daemon/OrphanReaper.php` — reaping processes a dead
  daemon left behind;
- `framework/backend/Core/Daemon/DockerManager.php` (`startDaemon()` — starting
  the daemon inside its container).

## The daemon-spawned command site

[../cli/command-execution.md](../cli/command-execution.md) declares the site
`daemon-spawned`: a CLI command whose child the daemon starts itself, with no
operator at its entrance. The site is a legitimate category and remains the
entrance for what is already built that way — today exactly two commands carry
it, `backup:run` and `backup:restore-run`, both from the backup. Declaring the
site is not a licence to move *new* long work into a child: the site says where a
command's work happens, this rule says whether that work may leave the agent at
all.

## Validation

`composer test:framework:unit` — the `DOC-LINK` guard catches a broken link to
or from this file; whether new long work took the right shape is judged by
reading, not by a check.
