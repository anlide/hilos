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

2a. **Migrations**
   - Framework provides stub migrations in `framework/backend/Database/Migration/Stub/`
   - Project copies required SQL files into its own migrations (e.g. 014_create_hilos_settings.sql)
   - `Stub/` folder is reference SQL only; framework does not execute migrations

3. **Signal Router**
   - SignalRouter, PageSignalRouter, SignalData, SignalName, SignalType
   - Subscriptions: page, group, user
   - SignalSource, WebSocketSignalData

4. **Docker + Logging + Exceptions**
   - DockerManager, docker-compose (local, dev, prod)
   - Logger, log levels, rotation, agent-specific logs
   - Exception hierarchy: DatabaseException, MissingEnvironmentVariableException, Process\*, Runtime\*, Worker\*, etc.

5. **Constants + DTO**
   - CliCommands, DaemonConstants, EnvConstants, ExitCode, WorkerConstants
   - DaemonStatusDTO, WorkerRegisterDTO, SignalDTO, WebSocket\*, etc.

## Server text time zones

Use `TimeHelper::serverTextTimeZone()` to select the zone for server-generated
human-readable text. It returns a `DateTimeZone`; see the
[time-zone policy](agents/architecture/time-zones.md#server-text-and-human-readable-exports)
for its modes, precedence, and errors. Apply the zone to the original moment
with `DateTimeImmutable::setTimezone()` so the instant stays unchanged. Include
the offset and zone name when rendering a message timestamp, and use `getName()`
when a separate zone label is needed:

```php
use Hilos\Utils\Helpers\TimeHelper;

$moment = new DateTimeImmutable('2026-10-23T12:00:00Z');

// Account recipient: own zone, last browser zone, project, then UTC.
$accountZone = TimeHelper::serverTextTimeZone($userId);
$accountTime = $moment->setTimezone($accountZone);
$accountText = $accountTime->format('Y-m-d H:i P (e)');
$accountZoneName = $accountZone->getName();

// No-account recipient: saved browser zone, project, then UTC.
$guestZone = TimeHelper::serverTextTimeZone(browserTimeZone: $savedBrowserTimeZone);
$guestTime = $moment->setTimezone($guestZone);
$guestText = $guestTime->format('Y-m-d H:i P (e)');
$guestZoneName = $guestZone->getName();

// Operator without a human recipient: project, then UTC.
$operatorZone = TimeHelper::serverTextTimeZone();
$operatorTime = $moment->setTimezone($operatorZone);
$operatorText = $operatorTime->format('Y-m-d H:i P (e)');
$operatorZoneName = $operatorZone->getName();
```

> TODO(hilos-refactor): remove remaining legacy Idea-class references from generated/compatibility sections.
