---
name: hilos-master-process
description: Guard the master daemon process from heavy or blocking work. Use when about to add database access, file I/O, network calls, or other potentially slow or blocking operations to the master process — the connection-accept loop, the WebSocket 101 / handshake-welcome path, signal routing, or any per-connection code after $daemon->run() — or before proposing such work to the user.
---

# Hilos Master Process Discipline

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then
read the canonical rule before changing master-process code.

## Read First

- Heavy work in the master: `docs/agents/antipatterns/heavy-work-in-master.md`
- Event loop and what blocks it: `docs/agents/architecture/event-loop.md`
- Blocking in handlers and ticks: `docs/agents/antipatterns/blocking-in-ontick.md`

## Workflow

1. Decide where the code runs: the master runtime path (connection accept,
   101 / handshake, signal routing, anything per-connection after
   `$daemon->run()`) or a worker / monopolistic agent.
2. On the master runtime path, do not add DB, file, network, or CPU-heavy work.
   Move it to the worker handshake, action, or signal handler, or a monopolistic
   agent.
3. One-time bootstrap (before `$daemon->run()`) may read config and build
   artifacts.
4. Do not offer a heavy-master option to the user, even a "simple" one.

## Hard Rules

- Do not add DB, file, network, or CPU-heavy work to the master runtime path; use
  a worker or a monopolistic agent.
- Do not propose heavy work in the master as a recommended option.
- Never ship a heavy master in a demo — people copy demo code into real projects.
