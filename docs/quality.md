# Quality Guidelines

> Draft. To be expanded.

Concept and requirements for application quality on Hilos. Proposed quality aspects for user interfaces, admin panels, and multi-threaded services.

---

## Quality Aspects (Proposed Names)

- **Consistency** — UI and data state coherence
- **Refresh Stability** — Predictable behavior after page reload
- **Conflict-Safe Editing** — Modal-based editing with conflict resolution
- **Agent Responsiveness** — onTick timing and monopolistic agents
- **Heavy Computation Isolation** — Process for long-running algorithms

---

## Consistency

Every page in a Hilos application displays up-to-date data regardless of actions by users, admins, developers, operators, scripts, or the external environment.

If the user’s connection to the main Hilos daemon is lost, the system must clearly indicate this to the user.

---

## Refresh Stability

When the user’s connection is stable and they press refresh, the page must reload with the same logical state as before the refresh, except for transient UI elements such as tooltips, modals, and similar overlays.

---

## Conflict-Safe Editing

All data editing must be done in modal windows or other overlay UI so that conflicts can be resolved explicitly. Conflicts occur when:

- The same user edits the same data from different devices or different browser tabs
- Different users edit the same data concurrently

Hilos provides standard, universal mechanisms for resolving such conflicts; they must be used. See [reference.md](reference.md) (Frontend SDK — ConflictHeader, ConflictActions).

---

## Agent Responsiveness

The framework defines many events for agents. **onTick** is especially important and must complete in under 0.1 seconds.

Some operations will take longer or may take longer (e.g. sending messages to Telegram or Slack). For such operations, use a **monopolistic agent** (one agent per worker). Even if sending messages is the core value of your system, use one or more monopolistic agents for these tasks. See [reference.md](reference.md) (Daemon-Worker architecture — monopolistic workers).

---

## Heavy Computation Isolation

Complex mathematical computations should be split so that onTick stays under 0.1 seconds per tick. When this requirement would unreasonably complicate the algorithm, use [Process](/framework/backend/Core/Process.php) for isolated execution. Process allows you to run a heavy script and add progress reporting at any point without complicating the main algorithm.
