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
are named below, and so are those of HIL-1250 (the bridge), HIL-1251 (the
verdict, the refusal, the reading actions, the text of a failure) and HIL-1261
(the words of the mode, the refusal's sentence, the controls).

## Core Rule

The switch is the framework's, not a demo's: every project that mounts the
admin section gets the mode, and a demo only turns it on. With the mode on,
every viewer opens every `ADMIN` page — framework-owned and project-owned
alike — sees everything that is not personal, and changes nothing. The
server decides and the screen only follows: a button that cannot be clicked
is a courtesy, the server's refusal is the rule.

With the mode off, everything is byte for byte as it is today.

## Who Is A Viewer

A viewer is a connection on an `ADMIN` page, with the mode on, that does not
act as an admin (`BrowserContext::actsAsAdmin()`: its user passes `isAdmin()`,
or an administrator's own rights are carried into a takeover, HIL-1170) — a
session without an account and a signed-in non-admin alike. The word is
*viewer* and not *guest*, because a guest in Hilos is the session without an
account, one case of a viewer rather than another name for one. An admin is
never a viewer, and the line between the two moves live: granting the rights
turns a viewer's open pages into the full admin surface, and taking them away
turns the pages back into the view, the browser following in the same
movement — by the same re-decision that
re-sends an open page when rights change today
([page-access-control.md](page-access-control.md), *Re-deciding an OPEN page
when rights change*). The browser follows because the session response carries
the node's mode beside the admin flag, and with the mode on the client's own
reaction to a moved flag waits for that answer instead of drawing a 403
(*The Browser Side*).

The mode touches `ADMIN` pages only. `PUBLIC` and `AUTHENTICATED` pages keep
their own rules — the profile stays the person's own and nobody else's. Roles
(HIL-306) are not the basis of the mode: a viewer is told apart by the same
`actsAsAdmin()` the gate reads today, and the authorization hook of HIL-309 will
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
  the mode?" — `BrowserContext::isAdminViewModeViewer()` — is built on it.
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

- `PageAccessGate` stays the single carrier of the rule and has three answers.
  `PageAccessGate::verdict()` returns `PageAccessVerdict::ALLOW` — look and
  act — or `PageAccessVerdict::VIEW` — look only; a refusal stays what it was,
  `PageUnauthorizedException` (401) or `PageForbiddenException` (403). *View*
  is three facts together: an `ADMIN` page, the mode on, a connection that did
  not prove an admin — the one question `BrowserContext::isAdminViewModeViewer()`
  asks. The order: a `PUBLIC` page allows; a viewer views; an anonymous
  session is refused 401; a non-admin on an `ADMIN` page 403; anything else
  allows. With the mode on a failed admin lookup views; with it off it throws
  as it always has.
- Two questions are asked of it. *May the connection look* —
  `PageAccessGate::assert()`, where *view* passes: the subscription and its
  update are let through, every delivery goes — the reactive fan-out and the
  table windows — and live updates reach a viewer as they reach an admin.
  Everything that leaves for a viewer passes the bridge (*Personal Fields On
  The Wire* below). *May it act* — `PageAccessGate::assertAction()`, below.
- A table's frames — the window (scroll, sort, search, page), the facets, the
  mark of rendered fields, the row focus under an open form — are looking, not
  acting: they change only the state of the connection's own window, and they
  travel as frames of their own, not as actions.
- A page action is refused to a viewer, except the ones the page declared
  reading (*Reading Actions* below): `ActionViewModeException`, code
  `view_mode`, HTTP 403, and the impersonal reason
  (`SignalConstants::ACTION_FAILED_REASON`) — never the exception's text; the
  sentence on the screen is the frontend's (HIL-1261): the core reads the code
  (`VIEW_MODE_ERROR_CODE`) and shows `HILOS_VIEW_MODE_COPY.refusal` in place of
  the reason (`actionFailureReason`, called by `ActionLifecycle` and
  `ActionErrorStore`), for the three frontends at once, and
  `ActionError.errorCode` carries the code. The code is its own and
  not `forbidden`, so a viewer without an account is not answered with the
  sign-in modal. The refusal is a verdict, not a failure: one INFO line in the
  journal and no trace, because the overview of the logs collects ERROR lines
  and a viewer is looking at exactly that page.
- A refused action writes nothing — not to the database, not to RT: the page's
  refusal handler records no state. The case the rule is written from is the
  Guardian page of the chat demo.
- The error's text — the exception's class and message — goes to a connection
  that proves an admin on the page at the moment of the answer
  (`PageAccessGate::provesAdmin()`: an `ADMIN` page whose verdict for this
  connection is *allow*), not to anyone on an `ADMIN` page: not to a viewer,
  and — with the mode off too — not to a non-admin whose action the gate
  refused. The dispatcher's tracked answer and the answer to a write handed
  over to the row's owner (`HandoverGatekeeperTrait`) ask it alike. A page
  that answers a failure with a frame of its own puts
  `AbstractPage::failureText()` into it — the message for the one who proves
  an admin, the impersonal sentence for everyone else — never `getMessage()`.
- The mode opens a page by its level: every `ADMIN` page — framework-owned and
  project-owned alike — is in it. A page's `ACCESS` guard is judged after the
  level, as always, and does not let a viewer through, so a page closed by a
  guard alone stays closed to one. An admin page is therefore closed by the
  `ADMIN` level, not by a guard on the admin flag: the chat demo's user table,
  the one page that had such a guard, dropped it and keeps the level alone.
- Taking the rights away or giving them back switches an open page by the
  re-decision that already re-sends it
  ([page-access-control.md](page-access-control.md), *Re-deciding an OPEN
  page when rights change*): with the mode on, the page of a person whose
  rights were taken is answered with the view instead of the 403, and the page
  of a person given them with the full page.
- The freeze ([protected-mode.md](protected-mode.md)) closes a viewer as it
  closes everyone; that does not change.

## Reading Actions

A reading action writes nothing and sends nothing to anyone — no database, no
RT, no file, no message to the outside. The page that holds the action declares
it reading in `AbstractPage::READING_ACTIONS`, a list of its own action names:
a viewer runs those (`PageAccessGate::assertAction()`) and is refused the rest.
The start refuses a name the page does not own through `ACTIONS` and a list on
a page that is not `ADMIN` (`TopologyValidator`), because either is silent at
run time — a typo quietly closes an action, or reads as opening one that no
viewer will ever reach. Today there are three: `LOGS_READ_LINES`
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
  exactly what they receive today. The hidden mark is the object
  `{"_hidden": true}` in place of the value (`HiddenValue`). It lives in the
  value itself, not in a side list of hidden names, because the frontend files
  a fragment that carries an id into its entity store by (type, id) and a side
  list would not survive that; the leading underscore is a reserved name, like
  the entity's `_standalone`.
- The core reads the mark once (HIL-1260), and only for the fields that stay
  hidden after the marking and reach a viewer's screen — the personal ones.
  `isHiddenValue` recognizes it (the mirror of `HiddenValue::isMark`), and the
  readers hand out the one frozen `HIDDEN_VALUE` as a third state of the field,
  beside the value and null, typed `Hideable<T>`: `readHideableString`,
  `readHideableStringOrNull` and `readHideableBoolean` beside `readString`, and
  `hideable()` for a frame schema validated by core; and for a field of an entity
  whose typed shape stays plain — a person's name, read by the chat and the
  profile where it is never hidden — the collection's
  `EntityCollection.hidden(target, field)`. One instance and not the object off
  the wire, because the row-edit helper compares by `Object.is`: a hidden field
  is then unchanged to it, and an edit window opened on one is never dirty. A
  hidden null is not told from a hidden value — the server hides the field
  whole. Every other field reads as before, the mark folded into the empty
  string or null until its screen needs the third state.
- The source is the marking that already exists: `_pii` and `_piiNotPersonal`
  on an Entity, collected by `PiiRegistry` — the same verdict a restore
  anonymizes by ([backup-anonymization.md](backup-anonymization.md)). There is
  no second marking, and none is to be introduced. A column in `_pii`, under
  any strategy — and every column of a table under
  `AnonymizationStrategy::PURGE` — is hidden; a column in `_piiNotPersonal`
  is shown; a column in neither is hidden.
- A wire field is shown only when something said *not personal*: the verdict
  of the column it came from, or a declaration on the field itself — for a
  computed field and for one that came from RT. The declaration is a map of
  wire name to `WireField`: `WireField::column($collection, $field)` for a
  value copied out of a column (the Object field of a mounted collection;
  its verdict decides, the declaration never does),
  `WireField::notPersonal()` for a computed or RT field, and
  `WireField::each($fields)` for a nested object or a list of them. A key the
  map does not name is hidden, and so is a scalar where `each` was declared.
  The walk is one function for every point below (`ViewerFields::hide()`).
- The strategy does not travel on the wire: a viewer sees the hidden mark —
  not a fake name, not a mask of the kind `a•••@gmail.com`, not a truncated
  value (the owner's word, 2026-09-26).
- Where: all three points of the wire, plus the frames of pages that keep a
  subscriber set of their own. No frame of an `ADMIN` page reaches a viewer
  past the bridge.
  - The row of a typed table, declared by `TableDefinition::wireFields()` (the
    row's own field names, the words the sort and the search use). It is
    hidden in the one place a row turns into its wire form, so every frame it
    rides — the window, the window section of the page answer, the delta, the
    append, the author's own create, the body of a row held in focus — and
    every digest of a delivered row are of the hidden form: a change to a
    hidden field raises no delta. The row's key field travels as it is where
    it holds the row's own key — the address of the row, the same value as
    the row key; the same name holding a related entity's id is judged like
    any other field.
  - The declarative fields of a source: the row is hidden once it is whole,
    after every VIA join has read the real values. A field a database source
    projects out of a column is judged by that column's verdict; a field of an
    RT source and a computed one is shown only when the row config names it in
    the `notPersonal` key (`BrowserFieldKey::NOT_PERSONAL`). So is a field of a
    database item that is not a column — an overlay, a property its object
    computes (P-443): no verdict covers it, and the declaration opens it as it
    opens a computed one. `notPersonal` naming a field read out of a column is
    refused at the start, in its second moment
    (`TopologyValidator::validateReferences()`), where the collections are
    mounted and can say which of their fields are columns; and at run time
    such a field keeps its column's verdict even where the check did not run.
  - The page's own data, declared by `AbstractPage::dataFields()`; the page's
    other sections (entities, lists, tables) are hidden whole.
  - The frames of pages with a subscriber set of their own (the logs pages,
    the setting presets, the Legal acceptances): `AbstractPage::frameForViewer()`
    on every send, by the frame DTO's static `wireFields()`. Such a frame goes
    past the delivery guards, so it asks the gate's verdict for the connection
    itself, every time: a connection the gate refuses is sent nothing and stays
    in the set — promoted live the moment the gate lets it through — and that
    holds with the mode off too, so an admin whose rights were taken stops
    receiving the frames; a viewer is sent the hidden frame, an admin the frame
    as it is. A viewer's frame goes out untyped (`SignalData`) under the same
    signal name: a typed frame is rebuilt on the master by its own
    `fromArray()`, and a typed reader takes the mark for a malformed value.
- The window of a viewer is not sorted or searched by a field hidden from
  them: the places a window reports carry the values of its sort fields, and
  a search over a hidden field says, row by row, whether the hidden value
  holds the term. `BrowserContext::viewportForViewer()` narrows the window
  wherever it is remembered: the order loses its components over hidden
  fields (a single component left is served, two or more left of a longer
  order are no order at all), and the search reads only the searched fields
  the viewer is shown — with none of them shown the window is served
  unsearched rather than refused. The window section of the page answer names
  the order actually served. The place a tab pages on from stays: a table
  compares it only over the fields of the order it serves. The filters are not
  touched: no filter stands on a personal column. Unlike the question below,
  this narrowing is remembered with the window: a window remembered before a
  grant or a revoke keeps its scope until the page is subscribed again, which
  narrows it anew.
- The detail of a table's progress bar is the project's payload; a viewer is
  shown it by `TableDefinition::progressDetailFields()`, and the count of the
  work (current, total, ended) always.
- "Is this viewer in the mode?" is asked in one place of the server —
  `BrowserContext::isAdminViewModeViewer()`: an `ADMIN` page, the node's mode
  on, and a connection whose user is not an admin (a session without an
  account included). With the mode off nothing else is read. A failed admin
  lookup answers yes: the bridge closes rather than opens. It is asked on
  every delivery and never remembered, so a grant or a revoke is what the
  next frame goes by. A page does not read the variable itself.
- The page's shell fields — label, subtitle, breadcrumbs, child pages — are
  not personal, so a page stays recognizable to a viewer. For a viewer the
  catalog's shell is laid over the page's hidden data and wins, so a key of
  the page's own under a shell name never reaches them. The dashboard
  declares its sections not personal: they are the catalog too.
- Why *not said* means *hidden*: a surface added later is safe from birth, and
  marking only opens. That is also the order that holds production: the
  variable turned on in production (HIL-1249) opens nothing until the verdict
  (HIL-1251), and the verdict stands on the bridge (HIL-1250) — at no step is
  there a window in which a viewer sees anything personal.
- The owner's decisions per surface. The values of ALL settings are hidden
  while keys, types and captions are shown; there is no marking by key —
  `Setting.value` is `AnonymizationStrategy::MASK` whole, because the
  framework does not know what the project put there. On every settings screen,
  a viewer sees the key, type, caption, source, and presence of a value; the
  value itself, the default value, and the key of a referenced default are
  hidden; a switch-value is rendered as a hidden mark; log modes — card titles
  are shown, the applied mode, mode values, and drift are hidden; the set of
  enabled sign-in methods is hidden on the screen even though sent to every
  session — one rule for setting values; OAuth providers are shown according to
  the entity's verdict (HIL-1255). A log line shows its time, level and
  node, and its text is hidden; there is no marking at write time
  (not in the code yet — HIL-1257). A person's name is hidden (HIL-1254).
  Rows assembled by hand past the marking are classified by the leaves of
  their sections: the people rows and the merge candidates (HIL-1254); the
  verifier circle, the deliveries, the free text of backup refusals and the
  maintenance texts (HIL-1256).
- The people (HIL-1254). The list, the card of one person and the merge
  candidates show a viewer the id, the admin and block flags and the last
  activity by their column verdicts, the name hidden by its own; presence and
  the session count, worked out of runtime connections; whether a password is
  set, worked out of the identity's type; and the account standing whole — the
  fact shown, the block, the freeze, the documents and their deadlines, the
  date a scheduled deletion falls due — on the card's page data and on its live
  frame alike, by one map (`AccountStanding::wireFields()`). Hidden: the
  addresses, the unconfirmed password address among them; a candidate's
  sign-in methods whole, because the merge window reads the list as one value
  and the methods' types without their addresses tell a viewer nothing; the
  grace period of a deletion, which is the value of a setting. The date a
  deletion falls due is the one computed field out of a table under `PURGE` a
  viewer is shown: `hilos_account_deletion` is erased on a restore so that the
  copy does not go on to erase a masked person, not because the date names
  anybody, and without it the card would tell a viewer no deletion is
  scheduled (the owner, 2026-10-01). The table's columns stay hidden.

## The Browser Side

- The browser learns the mode from the server. The carrier is the key
  `adminViewMode` in the data section of the session response
  (`HandshakeResponseSignalData::withAdminViewMode()`), stamped by
  `AbstractAgent::sendHandshakeResponse()` on every response, the anonymous
  one included: a guest is the viewer it concerns most. It is the NODE's mode,
  not "this connection is a viewer" — the one place that question is asked
  stays on the server (`BrowserContext::isAdminViewModeViewer()`). The core
  reads it with `sessionAdminViewMode()`, true only when the server said true.
- What the admin section is to the browser is derived once, in the core:
  `hilosAdminAccess` is `'full'` for an admin (and inside a takeover whose
  `impersonationPolicy.carryAdmin` is on, HIL-1170), `'view'` for a non-admin with
  the mode on, `'none'` otherwise, bound by `bootHilos` (`bindAdminAccess`)
  from the admin flag and the mode of the same response. The admin gear, the
  mode banner and the controls of the mode all read it — the controls not
  directly but through the admin page shell, which provides "a viewer stands
  here" to what it holds (`useAdminViewMode`, provided by `HilosAdminPage`).
  The access is global: a signed-in non-admin with the mode on is `'view'` on
  every screen, and a control reading it directly would lock that viewer's own
  profile and the shell's controls — the deletion strip's "Keep my account",
  the impersonation strip's Stop, which under a takeover of a non-admin
  carries that person's identity. The three shells draw
  the gear from it for `full` and `view`, marked `data-access`, and a project
  passes nothing.
- The admin routes do not refuse a viewer on the client, and a grant or a
  revoke moves the open tab between the full surface and the view live:
  `bindAccessReaction` takes the mode as a third input, and with it on a
  moved admin flag on an administrative route drops the page's rows and waits
  for the server's answer — the view after a revoke, the full page after a
  grant — rather than drawing a 403 ahead of it. The client never decides
  access: what it drew by mistake is overridden by the server's first answer
  ([page-access-control.md](page-access-control.md), *Frontend*).
- The stand lever's flip is not sent to open tabs: a tab learns the mode on its
  next handshake. In production the mode does not change under a living
  process — it is decided at the master's start, and a restart drops every
  connection — and a broadcast of the flip without re-deciding the open pages
  would be half a movement
  ([../signals/screen-invalidation.md](../signals/screen-invalidation.md)).
- The mode banner stands on every admin screen — framework and project,
  Dashboard included — in the shell's banner strip (`data-id="view-mode-banner"`),
  for a viewer (`hilosAdminAccess` is `view`) on an admin route and not under
  maintenance: after the session's strips (replacement, deletion), before the
  project's slot, grey (`alert-secondary` — yellow, blue and red already mean
  "not well", frozen and blocked there), with an eye. A grant takes it down live
  and a revoke brings it back. The words of the banner are the core's —
  `HILOS_VIEW_MODE_COPY.mark` and `.explanation` (HIL-1261), one set for the
  three frontends — and its text carries the id `HILOS_VIEW_MODE_STRIP_TEXT_ID`.
- The hidden mark looks the same on every screen and in every cell — one
  component, `HilosHiddenMark`: a soft grey pill with a struck-out eye and the
  word `HILOS_VIEW_MODE_COPY.hidden` ("Hidden"; variant B, agreed by the owner
  on 30.09.2026). A value that may be hidden is drawn through `HilosHideable`,
  which hands a value to its slot narrowed and draws the mark for the hidden
  one. A string with no markup — a modal title, an aria-label — says the word
  through `hiddenAsWord`: "Rename · Hidden". A label chosen by a hidden value
  takes the wording true for every value (the legal root's lapsed count reads
  "Past deadline", `hilosLegalLapsedLabel`), a fallback that says "there is
  none" is said only when there truly is none ("No verified email"), and a
  button that opens a hidden text stays — the window says "Hidden".
- An edit window over a hidden field (F1): the field is replaced by the mark —
  no input, no list — and the draft holds the one hidden value, so the window is
  not dirty, raises no notice, and Cancel or Esc closes it without "Discard
  changes?"; Save is disabled by the mode.
- The controls of the mode: inside an admin page a viewer finds the action
  button (`LoadingButton`), the switch (`HilosSwitch`), the Save of an edit
  form (`ConflictActions`, the default button and the slotted one alike) and a
  table's bulk operations plainly disabled — their own color and size, paler,
  and no words. The explanation stands on the screen once, in the mode banner,
  and every control the mode disabled points
  at its text with `aria-describedby` — beside the description it already had.
  A disabled element shows no tooltip, so the reason is visible text and not a
  `title` ([../frontend/accessibility.md](../frontend/accessibility.md), rule
  `DISABLED-TITLE`). What stays live: Cancel and the fields of a form (a viewer
  opens it and reads it, and has nothing to save with), the conflict choices
  (they edit only the draft in the window), a button that only opens a form, a
  button that opens a window after the server's word (the person card: rights,
  block, deletion, merge, takeover — they first ask whether the administrator
  needs the confirmation step) is held by a `LoadingButton` with `opensWindow`,
  and the mode does not disable it; a viewer is not shown the confirmation step
  and the window opens at once (HIL-1263), a table's main action (it opens the page's own
  modal, whose button is a control of the mode), and marking rows. Outside the
  admin page shell nothing changes. An action a viewer still reaches — a raw
  button not moved yet, Enter in a form's field — is refused by the server and
  shown with the core's sentence (above).
- The "look only" flag these controls read has two sources (HIL-1170): the
  admin page shell (a viewer of the mode on this page, described by the
  view-mode strip) and the takeover scope around the page's area, true while a
  takeover only looks (`auth.impersonation.scope = view`, the core's
  `hilosTakeoverViewOnly`) and described by the impersonation strip
  (`HILOS_IMPERSONATION_STRIP_TEXT_ID`). In Vue the scope is
  `HilosTakeoverScope` and the controls read both through `useLookOnly()`;
  React and Angular carry it in their own `hilosLookOnly` context and token.
  The scope wraps the page's area and never the shell, so the strip's Stop
  stays live. The server refuses the same actions on its own
  ([page-access-control.md](page-access-control.md), *The takeover gate*).
- Each section moves its own raw one-click mutation buttons onto
  `LoadingButton`, and the Save of its own form onto `ConflictActions` or
  `LoadingButton`; a button that only opens a form stays as it is. Security has
  nothing to move: the switches of sign-in methods, of passkey without a
  confirmed address, of step-up operations and of impersonation are
  `HilosSwitch`es, the Save of its four windows — a two-factor setting, an
  OAuth return address, an OAuth provider field and the impersonation scope
  (HIL-1170) — stands on `ConflictActions` and `LoadingButton`, the
  confirms of its two resets are `LoadingButton`s, and its pencils and ↺ only
  open those windows (HIL-1267). The people section has nothing to move: the
  confirms of impersonation, rights, block, deletion and merge are
  `LoadingButton`s and a rename's Save stands on `ConflictActions`; the five
  buttons of the card that open their windows after the server's word carry
  `opensWindow`, so a viewer opens every window at once, without the confirmation
  step, and the merge window shows a candidate's name and sign-in addresses as
  the hidden mark (HIL-1263). Maintenance has nothing to move: the confirm
  buttons of its two dialogs — naming a verifier and taking one out — are
  `LoadingButton`s, and its two raw buttons only open those dialogs (HIL-1265). Log takeouts have nothing to move: the confirm
  buttons of their two dialogs — confirming a takeout and withdrawing it — are
  `LoadingButton`s, and their raw buttons only open or close a dialog or
  filter the list (HIL-1268). Legal has nothing to move: its one mutation,
  the Save of its settings form, stands on `ConflictActions` and
  `LoadingButton` since it was built (HIL-941), and its other buttons only
  open a form, a preview or a page (HIL-1269). Communications has nothing to
  move: its three one-click writes — a channel's switch on the hub, the test
  send and a failed delivery's retry — are a `HilosSwitch` and
  `LoadingButton`s, the Save of a field's form stands on `ConflictActions` and
  `LoadingButton`, the confirm of a field's reset is a `LoadingButton`, and a
  field's pencil and ↺ only open those dialogs (HIL-1266). The settings have
  nothing to move: the Save of their edit form stands on `ConflictActions`, the
  confirm buttons of a reset and of an orphan's deletion are `LoadingButton`s,
  and the three raw buttons of a row only open those dialogs. The log modes move
  their cards onto `LoadingButton`: a card applies its mode at once, so a
  viewer finds every card disabled, and the question a card asks before it
  overwrites hand-made edits never opens for a viewer — it holds no field to
  look at (HIL-1262). The chat demo's own admin has nothing to move: the Saves
  of its bot and prompt-piece dialogs and of a chat user's rename stand on
  `ConflictActions` and `LoadingButton`, the confirms of its two deletions are
  `LoadingButton`s, its raw buttons only open those dialogs, and the Guardian's
  pages have no view to hold a control (HIL-1270). Backup has nothing to move:
  the confirm buttons of its four dialogs — creating, deleting, restoring and
  reopening — are `LoadingButton`s, its keep toggle is a `HilosSwitch`,
  deleting the marked copies is an operation of the table's selection panel,
  and its raw buttons only open or close a dialog (HIL-1264).
- The controls exist in all three frontends with full parity, as every
  primitive does
  ([../frontend/multiframework-core.md](../frontend/multiframework-core.md)).

## What A New Admin Section Owes

Nothing — to be safe. An `ADMIN` page is in the mode from birth: its fields
are hidden until they are declared, its actions are refused until they are
declared reading.

Five things — to be useful to a viewer:

1. Declare its reading actions (`AbstractPage::READING_ACTIONS`).
2. Declare the not-personal fields of its rows and frames — by the verdict of
   the column or by a declaration on the field: `TableDefinition::wireFields()`
   for a typed table's row, the `notPersonal` key for a declarative row's RT
   and computed fields, `AbstractPage::dataFields()` for the page's own data,
   a static `wireFields()` on the DTO of a frame sent to its own subscriber
   set — and never declare a column holding a person's data not-personal for
   the viewer's sake.
3. Build every mutation out of the controls of the mode.
4. Put no exception text into a frame by itself: a frame of its own carries
   `AbstractPage::failureText()`.
5. Send its frames by the page's path, never past the bridge: a frame sent to
   the page's own subscriber set goes through `AbstractPage::frameForViewer()`
   for every connection.

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
the scenarios that expect a non-admin to be refused run with it off. In the chat
e2e the lever is `setAdminViewMode()`
(`demo/chat/tests/e2e/helpers/adminViewMode.ts`); it acts on the whole node, so a
spec that turns it on turns it off in its `afterEach`, and a tab opened before the
flip is opened again to learn it. The operations sections carry their viewer cases
in binance-btc-tracker
([../frontend/testing-strategy.md](../frontend/testing-strategy.md), "Which demo carries a spec"),
where the lever is the same `setAdminViewMode()` of
`demo/binance-btc-tracker/tests/e2e/helpers/adminViewMode.ts`.

Where every demo carries its viewer scenarios, and the refusals that run beside
them with the mode off, is the table below (HIL-1273). A spec is named by its
file under `demo/<demo>/tests/e2e/tests/`; every demo that turns the mode on
carries its own copy of the lever in `tests/e2e/helpers/adminViewMode.ts`.

**Viewer scenarios by demo.**

| Demo | Kit | Side | Looks (mode on) | Refused (mode off) |
|---|---|---|---|---|
| chat | Vue | account and showcase | `admin-view-mode` (a guest's gear; the strip on `/hilos`, `/hilos/legal/acceptances` and `/hilos/app/users`, the personal data as hidden; a signed-in non-admin granted the full section and taken back to the view, live), `auth` (sign-in methods), `step-up`, `second-factor`, `legal-admin` (the Legal setting window), `bots`, `moderator`, `account-merge` (the merge window) | `admin-view-mode`, its first test (no gear while the mode is off) |
| binance-btc-tracker | Vue | operations | `settings`, `backup`, `protected-mode` (the maintenance circle), `communications`, `users` (the windows of a person's card) | `auth` (the surface in place of an admin page), `admin-gating` (the people page), `backup` (the backup page) |
| tasks | React | account | `admin-view-mode` (a guest's gear, the strip on the account screens, the acceptances as hidden, the Legal setting window) | `auth`, `a11y` (no gear) |
| ecommerce-shop | React | operations | `admin-view-mode` (the strip on the operations screens: settings, users, backup, maintenance) | `auth`, `users` |
| polls | Angular | account | `admin-view-mode` (a guest's gear, the strip on the account screens, the acceptances as hidden, the Legal setting window) | `auth`, `a11y` (no gear) |
| online-testing | Angular | operations | `admin-view-mode` (the strip on the operations screens: settings, users, maintenance and the six log screens) | `auth`, `users` (the refusal and the revoked grant) |

The React and Angular kits carry the viewer cases of their primitives and of
every section as units (`framework/frontend/react/test/`,
`framework/frontend/angular/test/`), mirroring the Vue ones. A guest's gear, the
strip on the account screens, the mark on the acceptances and the legal setting
window are an e2e of the account demo of each kit
(`demo/tasks/tests/e2e/tests/admin-view-mode.spec.ts`,
`demo/polls/tests/e2e/tests/admin-view-mode.spec.ts`); the strip on the
operations screens is an e2e of its operations demo
(`demo/ecommerce-shop/tests/e2e/tests/admin-view-mode.spec.ts`,
`demo/online-testing/tests/e2e/tests/admin-view-mode.spec.ts`). Each of the four
copies the lever into its own `tests/e2e/helpers/adminViewMode.ts`.

The log takeouts carry their viewer case as a unit of the rotations page
(`framework/frontend/vue/src/admin/logs/HilosLogsRotationsPage.test.ts`) and not
as an e2e: a batch awaiting takeout exists on a stand only after a forced
rotation, and only the rotation scenario of binance-btc-tracker pays for one
(HIL-1268).

The communications section carries the viewer cases of its two field dialogs and
of a delivery's retry as units of the channel and the deliveries pages
(`framework/frontend/vue/src/admin/communications/HilosCommunicationsChannelPage.test.ts`,
`framework/frontend/vue/src/admin/communications/HilosCommunicationsDeliveriesPage.test.ts`);
its e2e (`demo/binance-btc-tracker/tests/e2e/tests/communications.spec.ts`) walks
the hub's switch and the test send. The buttons that open those dialogs and the
retry stand on row fields — whether a field is editable, where its value comes
from, a delivery's status: a viewer sees the delivery status, so Retry stands
disabled for them (HIL-1256); the value of a channel field is hidden from a
viewer, while the source and editability are shown — the edit and reset controls
stand for a viewer the same as for an admin (HIL-1255).

The backup section carries the viewer cases of its keep toggle and of its
restore and reopen dialogs as a unit of its page
(`framework/frontend/vue/src/admin/backup/HilosBackupPage.test.ts`); its e2e
(`demo/binance-btc-tracker/tests/e2e/tests/backup.spec.ts`) walks the create
dialog, the delete dialog over a row and the panel of marked rows. The toggle and
the restore button stand on row fields, and the restore and reopen blocks on page
data: a viewer receives row fields and page data as an admin does (HIL-1256), so
a live viewer sees the keep toggle and the restore button disabled (HIL-1264).

The settings carry their viewer case in
`demo/binance-btc-tracker/tests/e2e/tests/settings.spec.ts` (HIL-1262; the area
moved with HIL-1219). The log modes carry theirs as a unit of the
setting-presets screen
(`framework/frontend/vue/src/admin/settings/HilosSettingPresetsPage.test.ts`)
and not as an e2e: a viewer receives the presets frame with masked fields,
cards are rendered disabled, and none of them is lit up (HIL-1255).

The people section carries its viewer case in
`demo/binance-btc-tracker/tests/e2e/tests/users.spec.ts` (HIL-1263, laid out by
HIL-1273). Its merge window is the account side and binance-btc-tracker wires no
merge, so that window's viewer case is
`demo/chat/tests/e2e/tests/account-merge.spec.ts`.

The security section carries its viewer cases as units of six pages
(`framework/frontend/vue/src/admin/security/HilosSecuritySignInMethodsPage.test.ts`,
`framework/frontend/vue/src/admin/security/HilosSecurityImpersonationPage.test.ts`,
`framework/frontend/vue/src/admin/security/HilosSecurityStepUpPage.test.ts`,
`framework/frontend/vue/src/admin/security/HilosSecurity2faPage.test.ts`,
`framework/frontend/vue/src/admin/security/HilosSecurityOauthPage.test.ts`,
`framework/frontend/vue/src/admin/security/HilosSecurityOauthProviderPage.test.ts`)
and in the chat e2e (`demo/chat/tests/e2e/tests/auth.spec.ts`,
`demo/chat/tests/e2e/tests/step-up.spec.ts`,
`demo/chat/tests/e2e/tests/second-factor.spec.ts`); what a viewer is shown in
security fields is HIL-1255's — values and switches are hidden as marks (HIL-1255).

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
- Naming here what a neighbouring leaf has yet to introduce — the carrier of
  the mode in the browser, the words on the screen. This page states the rule;
  the leaf states the name.

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
- [../frontend/accessibility.md](../frontend/accessibility.md) — why the
  reason is visible text — the banner.
- [../frontend/multiframework-core.md](../frontend/multiframework-core.md) —
  the controls in all three frontends.
