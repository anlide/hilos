# Time Zones: Moments, Project Clocks And Reader Clocks

Read this before carrying, storing, interpreting or formatting a moment,
selecting a time zone, or writing cron and retention boundaries.

This is the canonical time-zone rule of HIL-1528. Behavior still to be built
ends with the literal marker naming the leaf that lands it. The existing code
still carries mixed formats; the [migration table](#remaining-migration) names
their owners. A rule here is not a claim that those migrations have landed.

## A Moment On The Wire

A **moment** is one unambiguous point in time. In inter-process and
server-browser payloads, carry it as either a number of **milliseconds since
the Unix epoch** or an **ISO-8601 UTC string ending in `Z`**.

| Form | Example | Verdict |
|---|---|---|
| Epoch milliseconds | `1767225600000` | A moment on the wire |
| ISO-8601 UTC | `2026-01-01T00:00:00.000Z` | The same moment on the wire |
| Epoch seconds | `1767225600` | Forbidden on the wire |
| SQL or local datetime, `Y-m-d H:i:s` | `2026-01-01 00:00:00` | Forbidden on the wire: no zone |
| ISO-8601 with a nonzero offset | `2026-01-01T01:00:00+01:00` | Forbidden on the wire: use UTC with `Z` |

Do not add a zone offset to an epoch number to make a "local epoch". The number
identifies the moment; a time zone changes its presentation, not its value.
An SQL string inside storage does not become a valid payload just because its
writer runs in UTC. Interpret the storage value in its declared zone and
serialize the moment into one of the two wire forms.

The existing [TimeHelper](../../../framework/backend/Utils/Helpers/TimeHelper.php)
already supplies `nowMs()` and `sqlToMs()` for the millisecond wire rule of
HIL-486. Their presence does not mean every payload has been migrated. In
particular, a conversion helper is not proof that all process and database
defaults are already explicit UTC.

## Server UTC And The Project Zone

Set the daemon, workers, CLI and database connections explicitly to UTC,
independently of the container's `TZ`. `EntrypointPrelude::run()` and
`EntrypointPrelude::initEnvironment()` both begin with
`date_default_timezone_set(TimeConstants::UTC_TIME_ZONE)`. `DaemonApplication`,
`WorkerApplication` and `DockerApplication` call `run()`; `CliApplication`
calls `initEnvironment()` only. A fresh database handle runs
`DatabaseSql::SESSION_TIME_ZONE_UTC` (`SET SESSION time_zone = '+00:00'`)
before it is published, and the same statement runs on the two direct
`mysqli_connect` paths in `DbWaitCommand` and `DbTestResetCommand`.

The project's IANA time zone is the env key `HILOS_PROJECT_TIME_ZONE`. An
absent value is the catalog default `UTC`. An explicit empty string stays
empty and does not become that default. Read the zone only through
`Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string()`.
`ProjectTimeZoneStartupGuard::assertConfigured()` runs only inside
`EntrypointPrelude::run()`, after `.env` and the test override and before the
persistence callback, and refuses a value that is not an exact IANA name. The
CLI calls `initEnvironment()` and stays available when the project zone is
wrong; its PHP clock is still UTC. The welcome delivers that string as
`data.projectTimeZone`. `HandshakeWelcomeSignalData` requires the field and
has no constructor default; `WebSocketClient` passes the accessor's value.
The process reads the env value at start. A changed value applies on the next
start.

Apply the project zone explicitly to cron and backup retention buckets; do not
switch the process zone to switch the project clock. UTC process state must not
make a calendar boundary depend on which process happened to calculate it.
`CronRule` builds its own `DateTimeZone` from the env value for every
calculation, and `BackupPruner` receives one from its caller; neither calls
`date_default_timezone_set()`.

Every node of a cluster must declare the same project zone. Refuse a node whose
project zone differs; accepting it would let the cluster disagree on schedules
and retention boundaries (owner's decision, 2026-10-17).
(not in the code yet — HIL-1597)

## The Reader's Zone In The Browser

Resolve one effective reader zone in this order: **the person's own IANA zone
→ the current browser's zone → the project zone → UTC**. Skip an absent or
invalid browser zone. A guest uses the current browser with the same fallback
to project and UTC, without a manual choice.

[`resolveTimeZone()`](../../../framework/frontend/core/src/timeZone/timeZoneRule.ts)
is that order as one function: the first usable of own, browser and project,
else `UTC`. A name is usable when it is a non-empty string without outer
whitespace, is not a numeric offset (`+02:00`, `-0500`, `−05:00`), and
`Intl.DateTimeFormat` accepts it as `timeZone`; an alias the engine knows passes
under the name it came with, an unusable step is skipped without a word, and an
engine with no `Intl` answers `UTC`.
[`readBrowserTimeZone()`](../../../framework/frontend/core/src/timeZone/timeZoneBrowser.ts)
asks the engine for its resolved zone on every call, remembers nothing, and
returns `null` when the browser cannot say.

Each tab keeps the three inputs as one snapshot in
[timeZoneState.ts](../../../framework/frontend/core/src/timeZone/timeZoneState.ts)
and projects it into three read-only signals, exported from `@hilos/core` and
`@hilos/core/base`: `hilosTimeZone`, the effective zone, always a usable name;
`hilosOwnTimeZone`, the person's own usable choice, where `null` is Automatic, a
guest, or a name this engine cannot use; and `hilosBrowserTimeZone`, what the
browser said at its latest read, or `null`. One input event publishes one new
snapshot, so a reader never sees a zone between two steps of it. The snapshot is
the tab's own memory and not `Hilos::$rt`: the browser's zone belongs to that
browser alone, and the person's choice is already stored on the server.

`bootHilos` binds it with `bindTimeZoneState()` right after the session scope
and before the socket opens, for every SDK. It reads the browser at once and
takes the project zone from the connection, then follows three sources:

- **Every welcome.** The project zone is `data.projectTimeZone`; a welcome
  without the field clears it.
- **Every `handshake_response`.** The person's own zone is
  `entities.currentUser.timeZone` of that frame (the configured current-user
  slot, never `impersonatedBy`). The frame is the source, not the merged session
  entity: a page or a group may overlay the same person, and a merge keeps a
  field the newer response omits. A user without the field, a `null`, an unusable
  name and a guest all read as Automatic; a payload whose container does not
  validate is no sign-out and keeps the last answer.
- **The browser itself.** Its zone is read again on bind, on every welcome and
  session response, on window `focus` and when the document becomes visible. There
  is no polling, so a hidden tab learns a new system zone when it returns.

A disconnect alone keeps the person's own zone: it changes with the next
response. A second bind releases the first; the release returned is idempotent
and leaves the last values readable. A change of the person's own zone reaches
every open tab through the session restate below, each tab applies the response
it receives, and a reset to Automatic returns each tab to its own browser.

The signals are the one effective value every SDK and demo reads, and no reader
is written yet. The core formatter reads `hilosTimeZone`
(not in the code yet — HIL-1584). The profile's Time zone row reads
`hilosOwnTimeZone` and `hilosBrowserTimeZone`
(not in the code yet — HIL-1585), and so does the selection window
(not in the code yet — HIL-1593).

**Automatic** means the person has no choice of their own, so the current
browser supplies the zone; it is not a zone name. The nullable account column
`hilos_user.time_zone` stores an explicit IANA choice; `NULL` means Automatic.
[UserActions::setTimeZone()](../../../framework/backend/Database/Actions/Item/UserActions.php)
accepts `null` or an exact identifier returned by
`DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC)`; it does not trim or
normalize names, accept offsets, update `last_activity`, or sync an unchanged
value. The person's own write is handled by
[AbstractUserAgent](../../../framework/backend/Users/Agent/AbstractUserAgent.php),
which receives `hilos_user_time_zone_write`
([UserTimeZoneWriteSignalData](../../../framework/backend/Users/DTO/UserTimeZoneWriteSignalData.php)),
writes its own row, and answers `hilos_user_time_zone_write_done`
([UserTimeZoneWriteDoneSignalData](../../../framework/backend/Users/DTO/UserTimeZoneWriteDoneSignalData.php))
with the same ask; the
[users library](../../../framework/backend/Auth/Library/AbstractUsersLibraryAgent.php)
resumes the original action from that done frame. After any successful write,
including a repeat, it requests the existing `hilos_user_sessions_restate` for
that person's sessions.
[AbstractAgent::handshakeIdentity()](../../../framework/backend/Core/Agent/AbstractAgent.php)
carries the stored value as
`handshake_response.entities.currentUser.timeZone` through
[HandshakeResponseSignalData](../../../framework/backend/Socket/WebSocket/DTO/HandshakeResponseSignalData.php);
a guest has `currentUser: null`, and an older payload without `timeZone` reads
as null. This value is on `currentUser`, not `impersonatedBy` or `data`. The
core applies it as the person's own zone, as described above; the window where a
person chooses a zone is not part of that mechanism.
(not in the code yet — HIL-1593)

The [User Entity](../../../framework/backend/Database/Entity/Item/User.php)
classifies `time_zone` as personal and assigns `NULLIFY` for restore
anonymization ([backup-anonymization.md](backup-anonymization.md)); the journal
records that the column changed without its value
([change-log.md](change-log.md)). Admin-view mode reads the same personal-field
verdict ([admin-view-mode.md](admin-view-mode.md)); a visible row for this value
in the admin table is a separate later change (HIL-1588).

## What The Browser Reports And The Person Keeps

The browser reports its IANA zone on **every connection and reconnection**,
including a guest's, as the query parameter `browserTimeZone` of the socket
address. [HilosConnection](../../../framework/frontend/core/src/connection/HilosConnection.ts)
builds the address in `socketUrl()` and reads `readBrowserTimeZone()` again
before every new socket, because the system zone may change while the page
lives. The address carries exactly one `browserTimeZone`: a copy in the
configured query is dropped, and a browser that cannot say leaves the parameter
out. It is the browser's own zone, never the person's choice or the project's.
The master adds no work for it; the parameter rides the handshake DTO's
`queryParams` to the sessions library in a worker.

The [sessions library](../../../framework/backend/Auth/Library/AbstractSessionsLibraryAgent.php)
writes it on every handshake, a guest's included, onto the session row
`hilos_session.browser_time_zone` through
[SessionActions::setBrowserTimeZone()](../../../framework/backend/Database/Actions/Item/SessionActions.php).
[BrowserTimeZone](../../../framework/backend/Auth/Session/BrowserTimeZone.php)
decides what is kept: a name is usable only as an exact member of
`DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC)`, so `UTC` and
backward aliases are kept as the browser named them and never rewritten to a
canonical name, while an empty or padded string, an unknown name, a numeric
offset and an abbreviation are kept as none. An absent or unusable name never
refuses the connection and does not supply a usable zone to the fallback chain;
it clears only the session's column, and a repeat of the stored value writes
nothing. A guest therefore writes the session and nothing else.

For a signed-in person, also persist the **last usable time zone reported by
that person's browser** on the person, in `hilos_user.last_browser_time_zone`.
This is separate from the session's zone and from the person's own
`time_zone`. The sessions library does not write the row: it sends the usable
zone of the session to the agent of the person at the keyboard as
`hilos_user_last_browser_time_zone_write`
([UserLastBrowserTimeZoneWriteSignalData](../../../framework/backend/Users/DTO/UserLastBrowserTimeZoneWriteSignalData.php),
`userId` and `lastBrowserTimeZone`), from one seam used at two moments: a
signed-in browser connecting, and a browser that signs in or registers without
reconnecting, whose session already holds the zone it named on its handshake. A
guest and a browser that named no usable zone send nothing. The frame is sent
every time, a repeat included, because an earlier one may not have arrived; one
that cannot be queued goes to the library's log, without the zone, and the
connection or the sign-in stands.

[AbstractUserAgent](../../../framework/backend/Users/Agent/AbstractUserAgent.php)
receives it on its indexed route and writes its own row through
[UserActions::setLastBrowserTimeZone()](../../../framework/backend/Database/Actions/Item/UserActions.php).
It is a background write that nobody waits on, so no answer frame comes back,
and a refusal goes to the agent's log, without the zone. A zone equal to the
stored one writes nothing and opens no receipt: the agent compares its own row
before the change-log receipt, so the repeat of every reconnect leaves no
journal row either. A change rides the receipt of the frame it came under, a
sign-in's, and otherwise the agent's own receipt with the action
`browser_time_zone_report`. The write does not touch `last_activity` and does
not restate the person's sessions.

The person's memory keeps the **last usable** zone. A report with no usable
zone writes nothing there, and no action clears the column: only restore
anonymization does. Offline text must not search surviving sessions for it, and
none needs to: the person's row is the one place to read. Session cleanup or
expiry must not lose the person's last browser zone (owner's decision,
2026-10-17, HIL-1582:26745). Deleting or purging sessions touches no person's
row, and a token rotation at sign-in moves the token and the person on the same
session row and leaves `browser_time_zone` as it was.

The zone describes the physical browser, so the person who gets it is the one
at its keyboard. Under an administrator's impersonation that is the
administrator
([Session::userAtKeyboard()](../../../framework/backend/Database/Object/Item/Session.php)
answers the impersonator, otherwise the signed-in person): the administrator's
agent receives the report, and the impersonated account never does and does not
change. Which zone a screen reads while an administrator acts as someone else
is a separate decision that this mechanism does not make.

Neither value reaches a browser. `Session::toArray()` and `User::toArray()` omit
them, the handshake response does not carry them, and the readers of the zone
on the screen use the browser's own `readBrowserTimeZone()`, not a server echo.

Both the session's browser zone and the person's last browser zone are personal
data. The [User Entity](../../../framework/backend/Database/Entity/Item/User.php)
classifies `last_browser_time_zone` as personal with `NULLIFY`, and the
[Session Entity](../../../framework/backend/Database/Entity/Item/Session.php)
is `PURGE` as a whole, so restore anonymization erases both
([backup-anonymization.md](backup-anonymization.md)). The journal of the person
records that `last_browser_time_zone` changed without its value
([change-log.md](change-log.md)). No admin table row shows either value and no
browser payload carries it, so admin view mode has nothing of them to hide
([admin-view-mode.md](admin-view-mode.md)); a visible row would be a separate
later change that reads the same personal-field verdict.

## Server Text And Human-Readable Exports

Choose the server text mode in env: **`recipient` (default), `project`, or
`utc`**. In `recipient`, resolve **the person's own zone → the last browser zone
stored on that person → the project zone → UTC**. This differs from the browser
screen, which uses its current browser. With no human recipient, use the
project zone; `project` always uses that zone, and `utc` always uses UTC, even
without a recipient. Server text and notifications name the zone they use.
Leave the standard SMTP `Date` header unchanged. (not in the code yet — HIL-1578)

Use that same mode for the personal data archive, a person's analytics export
and the acceptances CSV. The CSV's recipient is the administrator who requested
it. Date the filename in the same zone as its contents. Dates in these files
are ISO-8601 with an offset, and the README states the zone. These are outputs
for a person, a separate boundary from a moment in a protocol payload; an
offset in an export does not permit an offset string on the wire.
(not in the code yet — HIL-1579)

## Cron, Retention And Logs

Interpret **every cron expression** in the project zone, including next-run
calculation and daylight saving time (DST). Calculate backup day, week, month
and year retention buckets in the project zone too.

One class carries the cron half for every rule, whoever registered it: the
daemon's own, an agent's, a backup schedule entry, the log rotation schedule.
`CronRule::shouldRun()` and `CronRule::nextRunAfter()` read
`HILOS_PROJECT_TIME_ZONE` once per calculation, build an explicit
`DateTimeZone` from it and read the five fields from the instant placed on that
calendar. The process zone is not switched and nothing keeps the project zone
between calculations. The once-per-minute guard counts absolute minutes, so a
local minute that does not exist is skipped in spring, and in autumn both
absolute occurrences of a repeated local minute fire. `nextRunAfter()` returns
the start of the matching minute as an absolute moment.

`BackupPruner::selectForDeletion()` takes the zone and `now` as parameters and
reads no clock. It first moves `now` into that zone, and only then subtracts the
retention ages (`-N days`, `weeks`, `months`, `years`) of the bands, so an age
is a calendar step in the project zone, DST included, and a `now` that arrives
in UTC gives the same bands. The day, ISO-week, month and year buckets of every
row are formatted in the same zone. `BackupAgent::pruneHistory()` passes the
zone it builds from `HILOS_PROJECT_TIME_ZONE` and the current instant. The
byte-ceiling pass keeps ordering rows in UTC: it asks which row is older, which
no zone answers differently.

The resulting next run is still an absolute moment: on the wire it follows
[A Moment On The Wire](#a-moment-on-the-wire). A calendar used to calculate a
moment does not become a different wire format.

On disk, both a **log line's timestamp and a rotation batch's name use UTC
with `Z`**. The log screen uses the reader's zone. Rotation batches do not use
the project zone (owner's decision, 2026-10-17, HIL-1589:26749).
(not in the code yet — HIL-1589)

## One Core Formatter

One core formatter serves moment display in every SDK and demo. Its input is
epoch milliseconds or ISO-8601 UTC; its zone is the effective reader zone.
Provide date, time and date-and-time views, and calendar labels **today,
tomorrow and yesterday** calculated in that zone. Move helpers whose input is
already unambiguous onto this formatter. (not in the code yet — HIL-1584)

Preserve the absolute server moment when formatting it. Do not pass it through
the device clock correction in
[`serverClock.toLocal()`](../../../framework/frontend/core/src/session/serverClock.ts):
that would change the point in time before giving it a calendar representation.
The server's time is authoritative (owner's decision, 2026-10-17,
HIL-1584:26746). (not in the code yet — HIL-1584)

The existing clock correction serves countdowns; this documentation leaf does
not change those countdowns.

### A Calendar Date Or Duration Is Not A Moment

A calendar date names a day, not a point in time. In particular, keep a legal
document revision's date explicitly UTC when adapting its display; do not move
it into another day using the reader's zone. (not in the code yet — HIL-1584)

A duration, a countdown and an ordinary number are not moments either. Do not
feed them to a moment formatter merely because their representation is numeric.

### Guard The Display Boundary

The machine guard must forbid bypassing the core formatter for date output
through `toLocale*String`, `Intl.DateTimeFormat`, or manual assembly from
`Date.get*` in TS/TSX and Vue/Angular templates. Numeric `toLocaleString` is
allowed: formatting a number is not formatting a date. Reading
`Intl.DateTimeFormat().resolvedOptions().timeZone` is also allowed: it discovers
browser metadata and does not display a moment. The guard and formatter are
specified here, not implemented by this documentation leaf.
(not in the code yet — HIL-1576)

## Remaining Migration

The wire norm applies to new work; existing payloads and screens still need
the migrations below. Each row names the leaf that removes that part of the
mixed formats, not a prerequisite for reading or applying this rule.

| Surface | Migration owner and status |
|---|---|
| Sessions and devices | (not in the code yet — HIL-1586) |
| Notifications and deliveries | (not in the code yet — HIL-1587) |
| People | (not in the code yet — HIL-1588) |
| Logs | (not in the code yet — HIL-1589) |
| Backups, restore and maintenance | (not in the code yet — HIL-1590) |
| Acceptances | (not in the code yet — HIL-1591) |
| Cron | (not in the code yet — HIL-1592) |
| React | (not in the code yet — HIL-1594) |
| Angular | (not in the code yet — HIL-1595) |

The leaf that lands a mechanism or migration removes its markers in the same
commit, following [A Rule Written Ahead Of Its Code](../rule-authoring.md#a-rule-written-ahead-of-its-code).
This documentation leaf does not clear them on the strength of a plan.

## Outside This Rule

- Relative time such as "5 minutes ago" or "in N minutes" belongs to a separate
  global component that the owner will implement; it is not a formatter mode
  decided here (owner's decision, HIL-1584:26746).
- The first day of the week and localized month names belong to HIL-1424.
- Suggestions of a country's time zones remain undecided. A locale does not
  store a time zone; see [languages and countries](languages-and-countries.md).
- Exact storage schemas, env keys and APIs belong to their implementation
  leaves. This rule declares no new DB/RT fields, signals, DTOs or routes; those
  changes still pass the contract gate.
