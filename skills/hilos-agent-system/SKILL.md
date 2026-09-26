---
name: hilos-agent-system
description: Add, modify, or review Hilos agents, AgentDaemon classes, AgentType constants, AgentManager factories, onStart/onTick/onStop hooks, signal handlers, truth sources, monopolistic agents, and long-running agent workflows. Use when creating a new agent type or changing agent lifecycle behavior, when declaring an agent that answers for a whole set of an entity rather than for one instance, where how many of it run and which node runs it are two separate questions, and when an agent serves one instance of something — a user, a document, a room — and you do not want one of them alive for every instance that was ever opened. Use it too when the agent answers for a table rather than for an entity — the surface a page draws over a set or over one instance, with the viewers' windows inside it. Use it too when you are about to move long or blocking work out of an agent — a child process, a background script, a process of your own — and need to know which shapes this framework allows. Use it too when the agent owns not a whole table but one set of its rows — everything that belongs to the one instance it answers for — and you are choosing the width of its claim.
---

# Hilos Agent System

Use this skill for agent business logic and registration work. Start by reading `agents.md`, then load only the docs relevant to the agent task.

## Read First

- Adding a new agent type: `docs/agents/agent-system/adding-agent.md`
- Writing or reviewing `onTick()`: `docs/agents/agent-system/ontick-rule.md`
- Truth sources, shared state, long operations: `docs/agents/agent-system/monopolistic-agent.md`
- Declaring what an agent owns and what it reads, the operations a claim
  carries, and its width — the whole collection, named keys, or one set of the
  table: `docs/agents/architecture/truth-source.md`
- Agent lifecycle and signal methods, and how long an agent that serves one
  instance stays alive: `docs/agents/architecture/agent-lifecycle.md`
- An agent that holds a whole entity's set — one entity one library, and which
  of the two placement axes is yours: `docs/agents/architecture/entity-libraries.md`
- An agent that answers for a table rather than for an entity — one holder per
  table per subject, the viewer's window as state inside it, reading without
  owning: `docs/agents/architecture/table-agents.md`
- An agent that owns a directory of files on one node, and why the picture it
  holds lives in its own memory rather than in RT: `docs/agents/architecture/logs.md`
- The agent writes a fact somebody has on screen right now — when the server
  owes that screen a move, and which frame moves it:
  `docs/agents/signals/screen-invalidation.md`
- Asking for a node freeze before a destructive operation: `docs/agents/architecture/protected-mode.md`
- Blocking anti-patterns: `docs/agents/antipatterns/blocking-in-ontick.md`
- Moving long or blocking work out of an agent, and why a child process is not the way: `docs/agents/antipatterns/child-process-for-long-work.md`
- Signal routing changes: use `$hilos-signals`

## Workflow

1. Decide whether the work is a new agent, an existing agent behavior change, or lifecycle cleanup.
2. For a new agent, add the `AgentType` constant, Agent class, AgentDaemon class,
   `Hilos::AGENTS` entry (`WORKER`, `DAEMON`, optional `INDEXED`),
   `TopologyAgentFactory` delegation in managers, and `AGENT_SIGNALS` or
   `SignalRouter` routes.
   For indexed multi-instance agents, declare
   `AGENT_SIGNALS[$signal][AgentSignalConfigKey::INDEX_FIELD]` to route by
   payload field declaratively — do not override `SignalRouter::getDestinations()`
   for this case.
3. Keep `onStart()` for registration/initialization, `onTick()` for tiny incremental work, and `onStop()` for cleanup.
4. Move long or blocking work out of `onTick()` and signal handlers.
5. In named signal handlers, omit empty `default` branches for intentionally
   ignored shared-broadcast names and document the ignore contract in PHPDoc.
6. Declare what the agent owns, and check the registry's
   `AgentRegistryKey::SCOPE` against that declaration. A database collection is
   declared on the class, in `OWNS_DB`: a map from collection key to the
   operations the owner may perform on its rows. A runtime collection is
   declared the same way, in `OWNS_RT`. There is no second form: production code
   that reaches the ownership registry and claims a collection in a call is
   refused by the `TRUTH-SOURCE-CLAIM` guard. Both maps, and what a claim
   carries, are in `docs/agents/architecture/truth-source.md`. Nothing
   takes a claim back from a hook: `WorkerManager` does, after `onStop()` has
   returned or thrown. An RT truth source is unique for the whole cluster, not
   per node, so an agent that owns one keeps the default `AgentScope::CLUSTER`
   and exists once —
   hosted by the leader, or placed by the policy where `AgentPlacement::POLICY`
   is declared. An agent declared `AgentScope::NODE` runs on every node and must
   therefore own no RT collection — a second owner splits it, and all the daemon
   can do about that is refuse the other node's writes and log
   `RT collection <key> has truth sources on two nodes` — and only when both nodes
   claim the same ROW with every operation. A claim by keys is the way to have
   one collection written from several nodes: an indexed agent names the
   collection in `OWNS_RT_ROWS` and its own index in `ownedRtRowKeys()`, the
   seam the resolver asks the live instance once at start, and owns those rows
   alone — which is what makes a placed fleet's state converge across the mesh.
   The maps of a half are exclusive: a collection named by two of them refuses
   the agent's start, as does one declared narrowly with a seam that names no
   row, or by a set with a seam that names no set key.
   Ask which width the agent needs before writing the map. An agent that owns
   everything belonging to one instance — this person's rows, this event's —
   owns neither the table nor a list of keys but a *set*: the rows whose set
   tree — the `_setVia` column climbed along `_foreign`, or the row's
   `_setShortPath` — ends at the agent's key. That third width names the
   collection in `OWNS_DB_SET` (`OWNS_RT_SET` for the runtime half) and its set
   key in `ownedDbSetKey()` (`ownedRtSetKey()`), and the agent does not choose
   the cut: on the runtime half the row class names the field that cuts the
   collection, in `RtState::SET_VIA`, and there is no tree. It is specified in *A Claim Over A Set* of
   `docs/agents/architecture/truth-source.md`, which says sentence by sentence
   how much of it the code holds; read that before writing against either name.
   Say what the agent may DO with the rows it claims when that is less than
   everything: `AbstractAgent::defaultTruthSourceOperations()` is the one place a
   kind of agent answers, and `AbstractUsersLibraryAgent` overrides it with adding
   and removing. One claim may still differ from its kind, wider or narrower,
   and then carries its own list of `TruthSourceOperation`.
   The guard names the operation it refused, so a wrong answer here reads as a
   `RtTruthSourceWriteNotAllowedException` on one action rather than on the agent.
7. When the agent answers for a *set* — a list, a search, a create with nothing
   to address yet, a command with no addressee — read
   `docs/agents/architecture/entity-libraries.md` before writing its registry
   entry. One entity gets one such agent, and how many instances run is a
   separate question from which node runs them.
8. When the agent answers for ONE instance — this user, this document, this
   room — decide how long it lives before writing the entry, because nothing
   else in the registry asks. Declaring `AgentRegistryKey::IDLE_TIMEOUT` is the
   whole of it: the agent starts on the first frame addressed to it and stops
   after that many seconds of silence with no subscriber left, and without the
   key it lives as long as the worker does. Give it
   `AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC` unless the agent has its own reason
   for a number, and override `hasWorkInFlight()` when it runs a long job
   nobody is talking to it about — see the idle-stop section of
   `docs/agents/architecture/agent-lifecycle.md`.
9. When a handler or a tick writes a fact instead of answering the request that
   asked for it, ask who is on an open screen that fact devalues. A screen that
   declared what you changed as its own source is served by the browser fan-out;
   one that did not, and whose next action now fails, is moved by the server —
   see `docs/agents/signals/screen-invalidation.md`.
10. Add focused tests through composer scripts when behavior changes.
11. After registering the agent or its `AGENT_SIGNALS` in the topology, update the
   demo's `*TopologyRegistryTest` snapshot and run its `test:unit` — a shared
   cross-ticket guard. See `docs/agents/app-topology.md` step 12.

## Hard Rules

- Never run `git commit` or `git push`.
- Never add routing logic directly inside agents; use topology declarations or `SignalRouter`.
- Routing for indexed multi-instance agents must stay declarative in `AGENT_SIGNALS` with `AgentSignalConfigKey::INDEX_FIELD`; do not add `switch` by signal name inside agents or routers for this purpose.
- Never let non-truth-source agents write to a DB/RT collection they do not own.
- Never let an agent declared `AgentScope::NODE` own an RT collection; a
  per-node replica may read a shared collection, and changes what it does not own
  by signalling the owner. An agent that owns rows rather than a collection claims
  them by key and still keeps `AgentScope::CLUSTER`: what may not be duplicated is
  the claim over a row, and a NODE-scoped agent would claim the same keys on every
  node.
- Never add Repository or Service layers above `DbCollection`.
- Never spawn a child process to carry your own PHP work out of an agent; put the work in a monopolistic agent, whose worker is its own. An external binary the framework cannot replace is the exception — `docs/agents/antipatterns/child-process-for-long-work.md`.
