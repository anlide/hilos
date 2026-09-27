# Personal Data Export

Read this before changing the archive builder, a project's `applyAccountExport`
seam, the blocked card's export controls, or the authenticated download
(HIL-303).

## One Copy Per Person

`hilos_data_export` is a durable queue with one row per person. An order
replaces that person's previous copy and file; ordering while `preparing` is a
quiet success. The states are `preparing`, `ready` and `failed`. Ready and
failed copies expire seven days after completion; the hourly sweep removes the
row and file.

`AbstractDataExportAgent` owns the queue outright. Every AUTH project registers
its subclass with `DataExportAgentDaemon` and policy placement, activates the
table, and registers the reserved `FsContext::DATA_EXPORT` directory. Activation
refuses missing obligations. The daemon proxy requires a monopolistic worker:
one whole archive is built per tick, with blocking I/O confined to that worker.

## Confirmation And The Blocked Card

`export_data` is a step-up operation. Its declaration permits the blocked person
held on the browser's session and passes an account with nothing to confirm
with. Impersonation remains refused. The signed-in profile uses the same
operation on `/profile/data`.

A refused sign-in credits this operation only if `provenBy` matches the method
`StepUpMethodResolver` would ask for now. A password cannot stand for a
connected second factor, and provider login credits nothing. A card raised by
losing the session has no fresh sign-in to credit and asks for confirmation
normally.

`hilos_data_export_order` resolves the person from the connection/session and
rechecks step-up. A blocked-card order also checks that the account remains
blocked. Product-access restrictions are not consulted.

The card's `dataExport` node arrives with `accountBlocked` in session state and
the handshake. Its connections join `DataExportGroup`; transitions send the same
node in `hilos_data_export_state`. Closing or changing the card removes the old
membership. The core store follows the initial node and group frames; the flow
orders through the action lifecycle. `HilosDataExport` in each SDK consumes that
store and flow. Their owner starts the store and disposes both when it is done.

## The Profile Section

`AbstractHilosProfileDataPage` serves `hilos_profile_data` at `/profile/data`
with AUTHENTICATED access. Each demo binds the page to its own subscription
agent. The `dataExport` section in its `page_response` carries the same archive
node, or null when no copy exists; after answering, the connection joins the
person's `DataExportGroup`. The chat profile root carries the same section and
joins the same group for its live summary.

`HilosProfileDataPage` mounts the shared `HilosDataExport` block without its own
heading or border (`titled=false`): the page's catalog heading names the
section. The account-deletion explanation links here and closes its window
without scheduling deletion. While impersonating, the block keeps the copy's
state but hides Download; ordering still receives the step-up refusal.

## Notifications

A recorded build outcome emits `data_export.ready` (info) or
`data_export.failed` (warning) through the notification owner. Both types are
optional and honor the person's enabled channels, including for a blocked
account. Their data carries `url: '/profile/data'`; the ready notice names the
copy's lifetime in days rather than a date in an unknown timezone.

An erased account or a request replaced during assembly gets no completion
notice. Expiry sends none either. Notification dispatch sits outside archive
failure handling, so it cannot turn a ready copy into a failed build.

## Building And Restarting

A new order leaves its first worker turn free to send the acknowledgement and
preparing state. Archive I/O starts on the following turn, after that flush.

The builder writes an uncompressed ZIP to a random `*.building.zip`, writes
explicit JSON projections of framework records, calls the project's seam, adds
`README.txt`, and renames the complete file before marking the request ready.
Dates inside the archive are ISO 8601 UTC. Secrets, tokens and push endpoints
are excluded. A project without push storage gets an empty push section.

`applyAccountExport(int $userId, DataExportWriter $writer)` must contribute the
project's person row and all content belonging to that person, excluding other
people's records. It refuses by default. `section()` writes JSON; `file()`
copies attachment bytes and returns the relative archive path the JSON should
reference.

A failure removes partial bytes, marks the request failed and logs the reason.
There is no automatic retry of a failed request. A restart retries a preparing
request from the beginning. Startup drops ready rows whose files disappeared;
startup and the hourly sweep remove files not named by a ready row.

Before publication the builder queries `accountDeletions->erasedOf()` directly:
a completed erasure discards the build without publication. After erasure
commits, `DataExportNotifier::forgetUser()` queues
`hilos_data_export_forget_user` to remove the copy. This also closes an erasure
arriving after the builder's final check. A late completion cannot finish a
request replaced while it was being built.

## Download And Storage

`GET /_hilos/data-export` is an agent HTTP route. The cookie or header session,
never a URL credential, selects the copy: signed in as its person, or holding
that person's block notice. Missing/expired sessions are refused, impersonation
is 403, and a missing, unfinished or expired copy is 404. Replies are not
cached.

A successful response is an attachment named `your-data-YYYY-MM-DD.zip`, dated
by readiness. `HILOS_DATA_EXPORT_XACCEL_LOCATION` makes nginx send the bytes
from its internal location. Empty, it sends a direct body up to the files
subsystem's 4 MiB ceiling; above that it returns 500 and logs which env value to
configure. The demos configure nginx for test/prod and direct same-origin
proxies for dev.

`data_export` is one directory shared by every node and its nginx. A
multi-machine installation supplies a shared volume; the framework does not copy
archives between nodes. A node without that shared storage may return 404.

## What Is Not Here

The frozen-screen link is HIL-500. Notification-menu entries are not clickable;
the notice text names the profile section. Access journal records are added by
HIL-1174. Cross-node archive transport and exports ordered by administrators for
another person are not part of this mechanism.
