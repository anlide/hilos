# Hilos Framework Reference

> Draft. To be expanded.

API and component reference documentation.

---

## Structure

1. **Daemon-Worker architecture**
   - DaemonManager, WorkerManager, WorkerServer
   - Agent, AgentManager, AgentDaemon, WorkerManager
   - Monopolistic vs regular workers; `requiresMonopolisticProcess()`
   - Event Loop (epoll, PHP Event extension)
   - CliManager, CLI commands → [cli-commands.md](cli-commands.md)

2. **ORM system**
   - **Database layer:** Entity, EntityCollection, Object, ObjectCollection, Db, DbCollection, DbContext
   - **Runtime layer:** RtContext, RtCollection, RtItem, RtState, RtStates, RtActions — runtime data complementing DB
   - Schema, TableInfo, IndexInfo, Filter, migrations
   - Generator, PhpType
   - *Frontend ORM subset:* `framework/frontend/src/stores`, `framework/frontend/src/types` (part of ORM, implementation in progress)

3. **Signal Router**
   - SignalRouter, PageSignalRouter, SignalData, SignalName, SignalType
   - Subscriptions: page, group, user
   - SignalSource, WebSocketSignalData

4. **Docker + Logging + Exceptions**
   - DockerManager, docker-compose (local, dev, prod)
   - Logger, log levels, rotation, agent-specific logs
   - Exception hierarchy: DatabaseException, MissingEnvironmentVariableException, Process\*, Runtime\*, Worker\*, etc.

5. **Frontend SDK**
   - WebSocketService, WebSocket plugin
   - Components: Modal, Table, ConflictHeader, ConflictActions, LoadingButton
   - Stores (base connection store), router, types

6. **Constants + DTO**
   - CliCommands, DaemonConstants, EnvConstants, ExitCode, WorkerConstants
   - DaemonStatusDTO, WorkerRegisterDTO, SignalDTO, WebSocket\*, etc.

> TODO(hilos-refactor): remove remaining legacy Idea-class references from generated/compatibility sections.
