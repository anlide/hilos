# Architecture: The Admin View Mode

Read this before building or changing an admin page, a browser table or an
action so that a non-admin can look at it and not act — and before classifying
a column, because the personal-data verdict now also decides what that viewer
is shown. The mode exists for demos and quick presentations: a non-admin opens
the admin section, sees everything, and changes nothing. In a real project it
is off.

This page is the target structure of epic HIL-1168, written ahead of its code.
Every sentence about a mechanism that does not exist yet carries a marker
naming the leaf that lands it, and that leaf clears the marker in the same
commit ([../rule-authoring.md](../rule-authoring.md), *A Rule Written Ahead Of
Its Code*). The leaves introduce their own names — the verdict, the wire form,
the words on the screen; this page states each requirement and the key of the
leaf, never the name. The names HIL-1249 introduced — the variable, the latch
file and table, the node's runtime row and the lever — are in the code now and
are named below.

## Core Rule

The switch is the framework's, not a demo's: every project that mounts the
admin section gets the mode, and a demo only turns it on. With the mode on,
every viewer opens every `ADMIN` page — framework-owned and project-owned
alike — sees everything that is not personal, and changes nothing
(not in the code yet — HIL-1251). The
server decides and the screen only follows: a button that cannot be clicked
is a courtesy, the server's refusal is the rule.

With the mode off, everything is byte for byte as it is today.

## Who Is A Viewer

A viewer is a connection on an `ADMIN` page, with the mode on, whose user does
not pass `isAdmin()` — a session without an account and a signed-in non-admin
alike. The word is *viewer* and not *guest*, because a guest in Hilos is the
session without an account, one case of a viewer rather than another name for
one. An admin is never a viewer, and the line between the two moves live:
granting the rights turns a viewer's open pages into the full admin surface,
and taking them away turns the pages back into the view
(not in the code yet — HIL-1251), the browser following in the same movement
(not in the code yet — HIL-1253) — by the same re-decision that re-sends an
open page when rights change today
([page-access-control.md](page-access-control.md), *Re-deciding an OPEN page
when rights change*).

The mode touches `ADMIN` pages only. `PUBLIC` and `AUTHENTICATED` pages keep
their own rules — the profile stays the person's own and nobody else's. Roles
(HIL-306) are not the basis of the mode: a viewer is told apart by the same
`isAdmin()` the gate reads today, and the authorization hook of HIL-309 will
change the body of that question, not the mode around it.

## The Switch And Its Prod Latch

- The switch is the framework variable `HILOS_ADMIN_VIEW_MODE_ENABLED` in the
  framework's variable catalog (`EnvCatalogStub`), off by default, and present
  as a commented line in the `.env.example` of the root and of chat, tasks,
  polls and cluster.
- It works in production, and the code says so outright — in the variable's
  PHPDoc and in `AdminViewModeStartupVerdict`, whose production branch with the
  variable on and no latch turns the mode on: the mode is made for demos, and
  the demos live in production.
- The production compose of every demo — the ones that exist and the ones to
  come — has it `true` in the daemon service, and `DemoStackComposeGuardTest`
  refuses a demo whose `docker/docker-compose.prod.yml` lacks it. The owner's
  word (2026-09-27), rendered in English: *in all of them — the current demos
  and the new ones that will be made.* The code and the production compose
  landed in one commit: the first start of the new code in a demo's production
  had to find the variable on, or it would have closed the demo for good.
- Production is `NonProductionGate` answering no — `prod`, `staging`, and any
  value of `APP_ENV` nobody recognizes. It is the same verdict that refuses
  every `test:*` command, so a node is either a stand (the lever works, no
  latch is read) or production (the latch works, no lever); a typo in
  `APP_ENV` does not switch the latch off.
- The mode is decided once, by the master, at the end of
  `DaemonManager::boot()` (`AdminViewModeStartup`) — before the first socket
  is bound and the first worker started
  ([daemon-lifecycle.md](daemon-lifecycle.md), *Startup sequence*). On a stand
  the mode is what the variable says, and the latch is neither read nor
  written.
- The latch has two halves, and either one closes the mode: the file
  `.hilos-admin-view-mode-latch.json` in the node's log root, next to the
  owner marker `.hilos-log-root-owner.json` ([logs.md](logs.md), *One Daemon
  Per Log Directory*), and the row of the framework table
  `hilos_admin_view_mode_latch`, which `Migration::initialize()` creates beside
  `migration` on every migrated database
  ([../orm/migrations.md](../orm/migrations.md)). A production node that
  starts with the mode off and finds neither half writes both, with a WARNING
  that the mode is closed on the installation for good.
- A file and a row, because each alone can be lost by an ordinary operation: a
  restore from an old archive can take the row (the table is replayed as the
  archive had it, or left alone when the archive does not carry it), and a
  sweep of the log directory or a move to another machine takes the file.
  Every start of a production node writes the missing half back from the one
  that survived, with a WARNING, so the mode reopens only when both are lost
  between two starts. `test:db:reset` never meets the latch: it refuses in
  exactly the environments the latch lives in. The environment is no third
  half — the node only reads it, and a copied compose would carry it along.
- A latch with the variable on: the node comes up, the mode stays off, and an
  ERROR on every start names the file, the table and how to lift the latch.
  The node is not refused: with the mode closed the admin section is closed
  anyway, and a refusal would only take the whole site down (the owner's
  decision, 2026-09-28).
- A latch that cannot be read or written — no table on a database nothing
  migrated, a log root the node cannot write — keeps the mode off with an
  ERROR, and the node comes up: the mode is never opened on the strength of a
  half-read latch. A file that is there but cannot be understood is a latch
  all the same; its contents are for the person who finds it.
- The latch is lifted only on purpose and only by hand: stop every node,
  delete the file on each node and the row
  (`DELETE FROM hilos_admin_view_mode_latch`), start again with the variable
  on. Delete one half only and the next start writes it back. There is no
  command and no switch for it (decision of the decomposition, 2026-09-27 —
  the same rule as for the log-root owner marker).
- Why a latch at all: a real project starts production with the mode off, and
  that first start closes the mode on that installation for good — a typo in
  the environment later on does not open the admin section to strangers.
- The node's answer lives in the framework runtime row
  `hilosAdminViewModeRuntime`
  ([../runtime/rt-context.md](../runtime/rt-context.md)): node-local, written
  only by the master — at its start and by the lever — and read by the node's
  workers, each handed the row when it comes up and kept current through the
  RT sync. The environment of a living process cannot change, so the row, not
  the variable, is the node's mode; the one place that asks "is this viewer in
  the mode?" is built on it (not in the code yet — HIL-1250).
- On a stand the lever `test:admin-view-mode on|off` turns the mode on and off
  until the daemon restarts, and touches no latch; the next start decides from
  the variable again. In a production-like environment the lever is refused,
  like every `test:*` command.
- In a cluster the file is each node's own — a node's log root is its own —
  and the row is the whole cluster's, the database being one: a new node of a
  production cluster that closed the mode finds it closed. The mode has to be
  the same on every node, and a node with a different value does not diverge
  silently (not in the code yet — HIL-1274).

## The View Verdict

- `PageAccessGate` stays the single carrier of the rule and gains a third
  answer beside *allow* and *refuse*: *view* (not in the code yet — HIL-1251).
  The condition is three facts together: an `ADMIN` page, the mode on, a
  connection that did not prove an admin. The verdict's API and value are
  HIL-1251's.
- The subscription and its update are let through; every delivery — the
  reactive fan-out and the table windows — goes, and live updates reach a
  viewer as they reach an admin (not in the code yet — HIL-1251). Everything
  that leaves for a viewer passes the bridge (*Personal Fields On The Wire*
  below).
- A table's frames — the window (scroll, sort, search, page), the facets, the
  mark of rendered fields, the row focus under an open form — are looking, not
  acting: they change only the state of the connection's own window, and they
  travel as frames of their own, not as actions.
- A page action is refused with the view mode as the reason, except the ones
  declared as reading (not in the code yet — HIL-1251); the refusal's code and
  reason are HIL-1251's.
- A refused action writes nothing — not to the database, not to RT: the page's
  refusal handler records no state. The case the rule is written from is the
  Guardian page of the chat demo.
- The error's text — the exception's class and message — goes to whoever
  proved an admin, not to anyone on an `ADMIN` page; today the page's level
  decides it (not in the code yet — HIL-1251). A project page does not put
  `getMessage()` into a frame by itself — its text follows the same rule
  (not in the code yet — HIL-1251).
- A project's admin page — closed by the `ADMIN` level or by an `ACCESS` guard
  on the admin flag — gets the mode the way a framework page does
  (not in the code yet — HIL-1251); how the guard lets a viewer through is
  HIL-1251's.
- The freeze ([protected-mode.md](protected-mode.md)) closes a viewer as it
  closes everyone; that does not change.

## Reading Actions

A reading action writes nothing and sends nothing to anyone — no database, no
RT, no file, no message to the outside. The page that holds the action declares
it reading; the form of the declaration is HIL-1251's
(not in the code yet — HIL-1251). Today there are three: `LOGS_READ_LINES`
(`logs_read_lines`), `LOGS_FOLLOW_START` (`logs_follow_start`) and
`LOGS_FOLLOW_STOP` (`logs_follow_stop`); the logs leaf declares them together
with hiding the text of the lines (not in the code yet — HIL-1257).

`COMMUNICATIONS_CHANNEL_TEST` (`communications_channel_test`) is not reading:
it sends a real message. When in doubt, the action writes.

The verdict closes the actions of the *page*. An agent action
(`AGENT_ACTIONS`) is looked up before the page's and is closed by
`AbstractAgent::AUTH_ACTIONS` alone, so the mode never sees it — which is why
an admin mutation keeps its name on the page: the rule *The Lock Does Not
Travel With The Name* of
[entity-libraries.md](entity-libraries.md#the-lock-does-not-travel-with-the-name-hil-771)
is here also a condition of the mode.

Outside the browser the mode opens nothing: a CLI command has no caller to
judge ([command-server.md](command-server.md), *Who may call a command —
nobody is asked*).

## Personal Fields On The Wire

- For a viewer the server writes the hidden mark in place of every field that
  is personal, or about which nothing says whether it is; an admin receives
  exactly what they receive today (not in the code yet — HIL-1250). This page
  calls the placeholder *the hidden mark*: its form on the wire is HIL-1250's,
  and the word the screen shows is HIL-1260's.
- The source is the marking that already exists: `_pii` and `_piiNotPersonal`
  on an Entity, collected by `PiiRegistry` — the same verdict a restore
  anonymizes by ([backup-anonymization.md](backup-anonymization.md)). There is
  no second marking, and none is to be introduced. A column in `_pii`, under
  any strategy — and every column of a table under
  `AnonymizationStrategy::PURGE` — is hidden; a column in `_piiNotPersonal`
  is shown; a column in neither is hidden.
- A wire field is shown only when something said *not personal*: the verdict
  of the column it came from, or a declaration on the field itself — for a
  computed field and for one that came from RT
  (not in the code yet — HIL-1250). The form of that declaration is
  HIL-1250's.
- The strategy does not travel on the wire: a viewer sees the hidden mark —
  not a fake name, not a mask of the kind `a•••@gmail.com`, not a truncated
  value (the owner's word, 2026-09-26).
- Where: all three points of the wire — the declarative fields of a source,
  the row of a typed table (built for each window separately, so hiding is
  per viewer), and the page's own data — plus the frames of pages that keep a
  subscriber set of their own and send through `sendToUser` (the logs pages,
  the setting presets). No frame of an `ADMIN` page reaches a viewer past the
  bridge (not in the code yet — HIL-1250).
- "Is this viewer in the mode?" is asked in one place of the server
  (not in the code yet — HIL-1250); a page does not read the variable itself.
- The page's shell fields — label, subtitle, breadcrumbs, child pages — are
  not personal, so a page stays recognizable to a viewer
  (not in the code yet — HIL-1250).
- Why *not said* means *hidden*: a surface added later is safe from birth, and
  marking only opens. That is also the order that holds production: the
  variable turned on in production (HIL-1249) opens nothing until the verdict
  (HIL-1251), and the verdict stands on the bridge (HIL-1250) — at no step is
  there a window in which a viewer sees anything personal.
- The owner's decisions per surface. The values of ALL settings are hidden
  while keys, types and captions are shown; there is no marking by key —
  `Setting.value` is `AnonymizationStrategy::MASK` whole, because the
  framework does not know what the project put there
  (not in the code yet — HIL-1255). A log line shows its time, level and
  node, and its text is hidden; there is no marking at write time
  (not in the code yet — HIL-1257). A person's name is hidden
  (not in the code yet — HIL-1254). Rows assembled by hand past the marking
  are classified by the leaves of their sections: the people rows and the
  merge candidates (not in the code yet — HIL-1254); the verifier circle, the
  deliveries, the free text of backup refusals and the maintenance texts
  (not in the code yet — HIL-1256).

## The Browser Side

- The browser learns from the server that it is in the mode; the carrier is
  HIL-1253's (not in the code yet — HIL-1253). The admin gear in the header is
  shown to a viewer, the admin routes do not refuse one on the client, and a
  grant or a revoke moves the open tab between the full surface and the view
  live (not in the code yet — HIL-1253). The client never decides access: what
  it drew by mistake is overridden by the server's first answer
  ([page-access-control.md](page-access-control.md), *Frontend*).
- The mode banner stands on every admin screen — framework and project,
  Dashboard included — in the shell's banner strip, beside the protected-mode
  and replacement banners (not in the code yet — HIL-1260).
- The hidden mark looks the same on every screen and in every cell — one
  component (not in the code yet — HIL-1260). The English words of the banner
  and of the mark, and the component's name, are HIL-1260's.
- The controls of the mode (not in the code yet — HIL-1261): the action button
  (`LoadingButton`), the switch (`HilosSwitch`) and a table's main and bulk
  actions (`mainAction`, `bulkActions`) cannot be clicked and carry the mode's
  mark; a form opens, Cancel stays, and the mark stands where Save was. The
  mark is visible text, not a `title`: a disabled element shows no tooltip
  ([../frontend/accessibility.md](../frontend/accessibility.md), rule
  `DISABLED-TITLE`). The form primitive is HIL-1261's.
- Each section moves its own raw mutation buttons onto the controls of the
  mode: settings and log modes (not in the code yet — HIL-1262); people —
  impersonation, rights, block, deletion, merge, rename
  (not in the code yet — HIL-1263); backup (not in the code yet — HIL-1264);
  maintenance (not in the code yet — HIL-1265); communications
  (not in the code yet — HIL-1266); security — OAuth, sign-in methods,
  two-factor, step-up (not in the code yet — HIL-1267); log takeouts
  (not in the code yet — HIL-1268); Legal (not in the code yet — HIL-1269);
  the chat demo's own admin (not in the code yet — HIL-1270).
- The controls exist in all three frontends with full parity, as every
  primitive does
  ([../frontend/multiframework-core.md](../frontend/multiframework-core.md));
  the React side (not in the code yet — HIL-1271) and the Angular side
  (not in the code yet — HIL-1272) follow the Vue one.

## What A New Admin Section Owes

Nothing — to be safe. An `ADMIN` page is in the mode from birth: its fields
are hidden until they are declared, its actions are refused until they are
declared reading.

Five things — to be useful to a viewer:

1. Declare its reading actions (not in the code yet — HIL-1251).
2. Declare the not-personal fields of its rows and frames — by the verdict of
   the column or by a declaration on the field — and never declare a column
   holding a person's data not-personal for the viewer's sake
   (not in the code yet — HIL-1250).
3. Build every mutation out of the controls of the mode
   (not in the code yet — HIL-1261).
4. Put no exception text into a frame by itself
   (not in the code yet — HIL-1251).
5. Send its frames by the page's path, never past the bridge
   (not in the code yet — HIL-1250).

Prove it with an integration test under a viewer: the frames carry nothing
personal, and a writing action is refused. That is how the section leaves of
the epic do it, and the test travels with the section, not as a leaf of its
own.

The first sections born under this rule: Legal (HIL-941; its mode —
HIL-1258 for what it shows, HIL-1269 for its controls), the Two-factor child
page (HIL-1204; its mode — HIL-1267), and the Guardian moved into the
framework (HIL-345, after HIL-1270).

## Tests

A viewer's e2e runs with the mode on (the lever `test:admin-view-mode on`);
the scenarios that expect a non-admin to be refused run with it off. How the viewer's e2e is
laid out across the three frontends is HIL-1273's
(not in the code yet — HIL-1273).

## What The View Mode Does Not Do

- It is not roles (HIL-306).
- It opens nothing to the CLI.
- A viewer changes nothing, demo data included.
- There is no marking of settings by key and no marking of log lines at write
  time (declined by the owner, 2026-09-27).
- There is no CLI reset of the latch.
- There are no masked values — a field is shown or it is the hidden mark.
- It does not touch `PUBLIC` and `AUTHENTICATED` pages.

## Anti-Patterns

- Closing a mutation on the client only. The server refuses; the disabled
  control is a courtesy.
- Showing a viewer a mask, a fake name or a truncated value instead of the
  hidden mark.
- Declaring an action reading when it writes or sends anything.
- Declaring a column not-personal so that a viewer may see it.
- Moving an admin mutation to an agent action to get it past the verdict.
- Sending a frame of an admin page through `sendToUser` past the bridge.
- Asking whether the mode is on by reading the variable on the page.
- Putting `getMessage()` into a frame.
- Naming here what a neighbouring leaf introduces — the verdict's value, the
  wire form of the hidden mark, the words on the screen. This page states the
  rule; the leaf states the name.

## Related

- [page-access-control.md](page-access-control.md) — the gate the verdict
  joins; the re-decision when rights change; the frontend's error view.
- [admin-features.md](admin-features.md) — what every admin page owes, beside
  the admin boundary.
- [backup-anonymization.md](backup-anonymization.md) — the verdict the bridge
  reads.
- [entity-libraries.md](entity-libraries.md) — why an admin mutation keeps its
  name on the page.
- [logs.md](logs.md) — the log root the latch file lives in.
- [daemon-lifecycle.md](daemon-lifecycle.md) — where in the start the mode is
  decided.
- [command-server.md](command-server.md) — why the CLI has no viewer.
- [protected-mode.md](protected-mode.md) — the freeze that closes a viewer
  like everyone.
- [../frontend/accessibility.md](../frontend/accessibility.md) — why the mark
  is visible text.
- [../frontend/multiframework-core.md](../frontend/multiframework-core.md) —
  the controls in all three frontends.
