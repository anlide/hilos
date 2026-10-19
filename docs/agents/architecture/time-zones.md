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
independently of the container's `TZ`. Declare the project's IANA time zone in
env; an absent declaration means UTC, an invalid explicit declaration refuses
startup. Expose it through the framework's env accessor and deliver the project
zone to the browser. The env key, accessor and delivery shape belong to the
implementation leaf. (not in the code yet — HIL-1577)

Apply the project zone explicitly to cron and backup retention buckets; do not
switch the process zone to switch the project clock. UTC process state must not
make a calendar boundary depend on which process happened to calculate it.
(not in the code yet — HIL-1581)

Every node of a cluster must declare the same project zone. Refuse a node whose
project zone differs; accepting it would let the cluster disagree on schedules
and retention boundaries (owner's decision, 2026-10-17).
(not in the code yet — HIL-1597)

## The Reader's Zone In The Browser

Resolve one effective reader zone in this order: **the person's own IANA zone
→ the current browser's zone → the project zone → UTC**. Skip an absent or
invalid browser zone. A guest uses the current browser with the same fallback
to project and UTC, without a manual choice. All SDKs and demos read the one
effective value. (not in the code yet — HIL-1583)

**Automatic** means the person has no choice of their own, so the current
browser supplies the zone; it is not a zone name. Keep an explicit choice in
the account. Only the person's agent writes it, and a change reaches all of
that person's sessions and tabs. (not in the code yet — HIL-1580)

The explicit choice is personal data: hide it in admin view mode and erase it
during restore anonymization (owner's decision, 2026-10-17).
(not in the code yet — HIL-1580)

## What The Browser Reports And The Person Keeps

The browser reports its IANA zone on **every connection and reconnection**,
including a guest's; the session stores it. An absent or invalid browser zone
does not supply a usable zone to the fallback chain.
(not in the code yet — HIL-1582)

For a signed-in person, also persist the **last time zone reported by that
person's browser** on the person. This is separate from the session's zone.
Offline text must not search surviving sessions for it: session cleanup or
expiry must not lose the person's last browser zone (owner's decision,
2026-10-17, HIL-1582:26745). (not in the code yet — HIL-1582)

Both the session's browser zone and the person's last browser zone are personal
data. Hide them in admin view mode and erase them during restore anonymization.
The implementation leaf owns the storage fields and the reporting channel;
this document does not name them. (not in the code yet — HIL-1582)

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
(not in the code yet — HIL-1581)

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
- Exact storage schemas, env keys, APIs and the channel that reports a browser
  zone belong to their implementation leaves. This rule declares no new DB/RT
  fields, signals, DTOs or routes; those changes still pass the contract gate.
