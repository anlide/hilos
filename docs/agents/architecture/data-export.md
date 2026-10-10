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
table, and registers the reserved `FsContext::DATA_EXPORT` directory as a
cluster one (`DirectoryScope::CLUSTER`). Activation refuses missing
obligations. The daemon proxy requires a monopolistic worker: one whole archive
is built per tick, with blocking I/O confined to that worker.

## Confirmation And The Blocked Card

`export_data` is a step-up operation. Its declaration permits the blocked person
held on the browser's session and passes an account with nothing to confirm
with. Impersonation remains refused. The signed-in profile uses the same
operation on `/profile/data`.

A refused sign-in credits this operation only if `provenBy` matches the method
`StepUpMethodResolver` would ask for now. A password cannot stand for a
connected second factor, and provider login credits nothing. A card raised by
losing the session has no fresh sign-in to credit and asks for confirmation
normally. The credit is the person's own confirmation, so the sessions library
hands it to the person's agent on `hilos_user_step_up_credit` without waiting,
and the agent writes it through `StepUpConfirmations::record()`, the one door
every confirmation takes (HIL-1407); an account folded into another one is
credited nothing. Every tab of the browser is told of it on
`hilos_step_up_confirmed` like any other confirmation (HIL-1330). A copy window
standing on its confirmation step closes on that frame and orders nothing - the
tab that confirmed is the one ordering - and so it does when Send again finds
the operation already confirmed.

`hilos_data_export_order` resolves the person from the connection/session and
rechecks step-up. A blocked-card order also checks that the account remains
blocked. Product-access restrictions are not consulted.

The card's `dataExport` node arrives with `accountBlocked` in session state and
the handshake. Its connections join `DataExportGroup` as the handshake response
is sent (`AbstractAgent::sendHandshakeResponse()`), after a change of person has
dropped every group of the connection (HIL-1284); transitions send the same node
in `hilos_data_export_state`. An anonymous tab answered without the card leaves
the membership, and a change of person removes it with every other group. The
core store follows the initial node and group frames; the flow orders through the
action lifecycle. `HilosDataExport` in each SDK consumes that store and flow.
Their owner starts the store and disposes both when it is done.

## The Profile Section

`AbstractHilosProfileDataPage` serves `hilos_profile_data` at `/profile/data`
with AUTHENTICATED access. Each demo binds the page to its own subscription
agent. The `dataExport` section in its `page_response` carries the same archive
node, or null when no copy exists; after answering, the connection joins the
person's `DataExportGroup`. The profile root (`AbstractHilosProfilePage`)
carries the same section and joins the same group for its live summary.

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
are excluded. A project without push storage gets an empty push section. Each
session carries the network address it last connected from, and `access_log`
lists the person's [access log](access-log.md) rows - `at`, `address`, `event`
in the order they occurred; a privacy text that keeps no address or no log gives
null and an empty list.

`legal_acceptances` holds every [acceptance record](legal-documents.md) for the
person in acceptance order: `document`, `revisionId`, `acceptedAt`, and `inCode`.
Declared revisions also carry `publishedOn`, `effectiveOn`, `significance`, and
`clauses` in the `LegalWire` form. A revision absent from the current catalog
stays in the copy with `inCode: false` and null revision details. A person with
no acceptances gets an empty list.

When analytics is enabled, `analytics_person.json` indexes numbered
`analytics_person_000001.json` parts of at most 500 events. It exists with
`parts: 0` when there are no events. The reader pages by source time and row id
through `hilos_analytics_person_event.user_id`: the person and every account
folded into them, including indirect merges. It never selects by the browser
session's last signed-in identity. A guest's activity before sign-in and another
person's activity in the same browser therefore stay out. Rows carry UTC time,
kind, action or page name, page params, address, session numbers and takeover
subject where applicable. They carry no session token, accept key or action
body. The [analytics writer](analytics.md) remains the only writer of the raw
table; the export reader issues bounded, read-only SQL from `Core/Analytics`.

When profile photos are enabled, `profile_photo` is null for a person without
one or whose original file is missing after a restore, or contains the cropped
JPEG under `files/<stored_name>` and `setAt` in UTC.
It reads the framework photo row and file registry; it does not export a
separate rendered variant.

`applyAccountExport(int $userId, DataExportWriter $writer)` must contribute the
project's person row and all content belonging to that person, excluding other
people's records. It refuses by default. `section()` writes JSON;
`file($name, $sourcePath)` copies a file's bytes to `files/<name>` and returns
the relative archive path the JSON should reference. A file kept in the
[files registry](files-registry.md) is read from its row: the chat takes the
name and the stored name from `hilos_file` and the bytes from
`Hilos::$fs->files[$storedName]`, so the archive holds `files/<stored_name>`.

A failure removes partial bytes, marks the request failed and logs the reason.
There is no automatic retry of a failed request. A restart retries a preparing
request from the beginning. Startup drops ready rows whose files disappeared;
startup and the hourly sweep remove files not named by a ready row. The marker
of the cluster directory is not one of them and stays: the sweep lists the
directory through `FsDirectory::entries()`, which leaves it out
([filesystem.md](filesystem.md), "The Guard").

Before publication the builder queries `accountDeletions->erasedOf()` directly:
a completed erasure discards the build without publication. Account erasure
deletes the order inside its transaction. After it commits,
`DataExportNotifier::forgetUser()` queues `hilos_data_export_forget_user` to
remove files no ready order keeps. This also closes an erasure arriving after
the builder's final check. A late completion cannot finish a request replaced
while it was being built.

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
configure. The direct body goes only to a browser this node holds; one on
another node gets the same 500 and a line naming its node, at any size
([files-registry.md](files-registry.md), "Serving A File"). The demos configure
nginx for test/prod and direct same-origin proxies for dev.

`data_export` is a cluster directory ([filesystem.md](filesystem.md)): every
node and its nginx see it — one machine, or a shared volume the installation
supplies; the framework does not carry archives between nodes. Startup drops
ready rows whose files it cannot see (the restart rule above), so on a node's
own directory a move of the agent wipes every ready copy. A node whose
`data_export` is not the one its neighbors see is not admitted into the cluster
([filesystem.md](filesystem.md), "The Guard").

## What Is Not Here

The freeze screen links here ("Download a copy of your data", HIL-500).
Notification-menu entries are not clickable;
the notice text names the profile section. Cross-node archive transport and
exports ordered by administrators for another person are not part of this
mechanism: the administrator's export of acceptance records repeats its shape
with its own queue, directory and address
([legal-documents.md](legal-documents.md), "Export of acceptances"), and shares
only the transport of the body, `PrivateDownloadResponse`.
