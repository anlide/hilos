# Wire Protocol

The contract between the browser and the Hilos daemon: how a connection is
authorized, how messages are framed and parsed, and the three kinds of message
that flow over the socket. The runtime behavior that consumes this protocol
(state machines, authoritative-backend, loading UX) is in
[core-and-connection.md](core-and-connection.md); this document is the protocol itself.

All communication rides one WebSocket connection. There are three kinds of
message:

- **signals** — backend → frontend (state pushes, action replies, errors);
- **actions** — frontend → backend (user actions);
- **subscribe** — frontend → backend (page and group subscription control).

## Moments on the wire

An absolute moment is a number of **milliseconds since the Unix epoch** or an
**ISO-8601 UTC string ending in `Z`**. Epoch seconds, a local string without a
zone and a string with a nonzero offset are not wire forms. Do not add a zone
offset to an epoch number; the reader's zone changes presentation, not the
moment.

This is the norm for new payloads, not a claim that all existing payloads have
been migrated. The canonical [time-zone rule](../architecture/time-zones.md)
defines the boundaries and lists the remaining migrations and their owners.

## The handshake is the authorization step

A connection is authorized at the WebSocket handshake — before any signal or
action flows. The handshake carries the request's cookies, headers, client IP,
and query parameters; the framework authenticates from them and binds the
resulting identity to the connection.

### Credential: an auth-only cookie

The session credential is an `httpOnly + Secure + SameSite=Strict` cookie set by
the server. Because it is `httpOnly`, application code physically cannot read it
— and must never try. It rides the handshake automatically.

- A cookie carries **only** the auth credential. Any non-auth use of a cookie is
  a gross violation (see [rules-and-violations.md](rules-and-violations.md)).
- Non-secret UI state (layout, drafts, a guest's theme) goes in **localStorage**.
  A signed-in person's theme lives in `hilos_user.theme_pick` (HIL-1427), and
  the browser keeps a copy only so the page
  wears it on its first frame; each valid non-null `currentUser.themePick` in
  the handshake refreshes that copy (HIL-1429). See
  [theme.md](../architecture/theme.md). Secrets
  never go in localStorage — it is a JS-readable API and cannot be made
  `httpOnly`.
- A token in a query parameter is forbidden (it leaks to logs and history).

This split closes the token-leak structurally: there is no code path that can
read or exfiltrate the credential.

### Server-side session store (not PHP session)

The source of truth is a server-side session held in Hilos's own store — **not**
PHP `$_SESSION` / `session_start()`, which is a poor fit for a long-running,
multi-worker WS daemon. The cookie carries an **opaque session id** (a random
token, not a JWT — no data inside) that the server looks up.

- Durable session rows live in `Hilos::$db` (survive a daemon restart).
- Live presence (which connections a session currently has) lives in
  `Hilos::$rt`. One session maps to N tabs / connections.
- At the handshake the framework reads the cookie, looks up `Hilos::$db`, and
  hydrates `Hilos::$rt`.

Stateful-opaque (not stateless JWT) is chosen so revocation is instant — a
revoked session is dead on its next lookup, and live connections can be kicked.

#### Session row shape

Sessions are keyed by a **hash of the opaque token**, never the raw token, so a
read-only DB leak cannot be replayed as a live session. The token is 256-bit
random, base64url-encoded; the hash is `HMAC-SHA256(token, app_key)` using a
global application-side pepper (`app_key`) that is **not** stored in the database
— a single indexed lookup plus defense-in-depth if only the DB leaks. No per-row
salt (it would break the indexed lookup and adds nothing against a 256-bit
token).

Columns: `token_hash` (unique, the lookup key) · `user_id` · `created_at` ·
`expires_at` · `last_seen_at` · `revoked_at` (nullable) · `ip` · `user_agent`.

#### Expiry, revocation, GC

- **Expiry** is a sliding `last_seen_at` with an absolute cap of **2 years** —
  that cap is the only bound (no separate idle-timeout).
- **Revocation** sets `revoked_at` and finds the session's live connections in
  `Hilos::$rt` to kick them (they transition to `auth-failed`).
- **GC** of expired and revoked rows is a light maintenance agent or cron.

`SameSite=Strict` plus an **Origin check** on the handshake defends against
cross-site WebSocket hijacking.

### Connection identity (connId)

Each connection has a server-assigned `connId`, minted server-side at accept
time and held in a `connId ↔ socket` map. The client never sends a connection
id; an incoming frame's connection is determined by the socket it arrived on,
and outgoing signals route by the server's own `connId → socket` map. The
WebSocket `Sec-WebSocket-Accept` value is a transport handshake artifact only —
browser JS cannot read it — and is never used as an app-level routing or
identity token. In the forked daemon/worker model the `connId` is minted by
whoever accepts the socket and shared across processes via IPC.

### Reconnect and auth-failed

Every new socket — including a reconnect — performs a fresh handshake and
re-authenticates. The cookie rides automatically, so re-auth is invisible unless
the credential is missing, expired, or revoked, in which case the connection
goes to `auth-failed` (a global login / "session expired" screen; see
[core-and-connection.md](core-and-connection.md)). No trust is remembered across sockets.

### The auth seam

The framework owns the auth machinery: cookie mechanics, the session store,
revocation, GC, the handshake protocol, and a default username/password login
flow. The project plugs in a credential verifier —
`HilosAuthProvider::verify(handshakeContext): SessionId | null` — and may
override the login flow. The framework calls the verifier at the handshake; on
success it establishes the session and binds it to the connection, on failure it
goes to `auth-failed`.

### Current state: guest auth in the demos

The session store and login flow above are the **target**. The three reference
demos (chat, tasks, polls) currently run a simplified **guest** model: the daemon
mints an `httpOnly` cookie on the 101 upgrade when a connection has none and
find-or-registers the user by it — no login, no password, and no separate
hashed-token session store yet. The project `user` row, keyed by its session
token, is the identity. Full username/password login and the hashed-token session
store described above are for other demos, later. Authentication (who you are)
stays a separate concern from authorization (what you may do).

## Message envelopes and parsing

Every message is a tagged envelope. Signals are discriminated by a `type` /
`dataType` tag; actions carry an `action` name and a `data` payload. The action
envelope does **not** carry a connection id — the connection is the socket it
arrives on.

### Signal envelope (BE→FE) and the handshake welcome

Every server→client frame is the envelope `{type, data, outcome?, time?}`:
`type` discriminates the signal, `data` carries its payload, `outcome`
(`success` | `fail`) marks action acknowledgements, and `time` is a reserved
server clock tick in milliseconds.

The first frame the server sends on every connection — directly behind the 101
upgrade response, before anything else — is the framework welcome:

```json
{"type": "handshake", "data": {"build": "<HILOS_BUILD_TIMESTAMP>", "projectTimeZone": "<HILOS_PROJECT_TIME_ZONE>"}}
```

`build` is the daemon's `HILOS_BUILD_TIMESTAMP` environment value (`dev` when
unset); it feeds the build-version check below. `build` stays required.

`projectTimeZone` is the project zone from the env key
`HILOS_PROJECT_TIME_ZONE`, the string
`Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string()` returns. A
current server sends a non-empty string. An older welcome that omits the
field, an empty string, and a non-string value are all accepted: the welcome
is kept, and `HilosConnection.projectTimeZone` stays `undefined`. The getter
stores the last welcome's zone and clears it when a later welcome omits the
field.

### Discriminated-union parsing (layered)

Discriminated-union parsing is the **only** allowed way to interpret a message.
There is one canonical parse boundary with runtime validation (**zod**, whose
`discriminatedUnion` matches this model) and compile-time exhaustiveness
(`assertNever`). Identifying a message by ad-hoc shape-sniffing (`typeof`,
`'x' in msg`) anywhere else is a gross violation.

Narrowing is **layered**, tightening at each step:

1. raw `unknown` off the socket;
2. an abstract `Signal` envelope;
3. narrowed by tag to a **category** (action reply, page update, handshake, …);
4. narrowed to a fully-typed **concrete** signal.

The framework owns the upper layers (envelope and category); the project types
the leaf concrete signals via declaration-merging (the `HilosActionMap`
pattern). Validation tightens until the payload is fully typed.

## Actions (FE→BE) and the action lifecycle

An action is a user action sent to the backend. Its lifecycle has three
outcomes:

1. **deferred loading** — the UI enters loading after ~0.3s, so fast operations
   never flash a spinner;
2. **personal reply** — the action's own `::success` / `::fail` releases loading
   and drives the reaction;
3. **timeout** — a ~30s (configurable) timeout is the third outcome.

Replies are correlated by a **client-minted `requestId`** that the backend
echoes on the reply: the connection addressee is always correct, but without a
`requestId` the client cannot always tell which action a reply answers — most of
all after a timeout. A timeout is an "inadmissible situation" that is still
handled gracefully: release the UI, but keep the orphaned action recorded so a
late reply can reconcile — never silently dropped. A late reply that finally
arrives surfaces as a toast (recommended; ultimately the project's discretion).
An action toasts its outcome only when the result is not visible on screen,
and a refusal of the user's own action is answered in the modal it came from as
well as in the corner — the surface rule is [toasts.md](toasts.md).

A state-changing action is **always tracked**: the initiator dispatches it
through this lifecycle and shows loading until it settles — it is **not**
fire-and-forget (a bare send with no correlated reply). When the action changes
shared or session state that other connections observe (a logout downgrading the
session, a rename fanning out), the personal `::success` only releases the
initiator's own loading; the **effect is broadcast as a signal** to every
affected connection, and each frontend reacts to that signal. Do not have one
connection mutate and assume the others discover it — send them the signal.

### A failure reason is a domain sentence, never an engine message

**The backend writes the words of a server refusal; the frontend displays them.**
Use the received `reason`, `title` or `message` as ready-to-show text. Machine
codes and HTTP statuses select behavior — which step to open, whether to invite
sign-in, what can be retried — never a frontend dictionary of refusal sentences.
This applies to action and page errors, auth and OAuth outcomes, identifier
detection, code-send progress, and legal consent replies. Preserve the words
through catches, replay and session/handshake delivery; a code must not replace
an explicit server message with a generic local sentence.

What travels on a `::fail` is a sentence about the thing the caller asked for:
"That name is already taken", "Backups are disabled". What must never travel is
the machinery underneath — driver text, SQL statements, index and column names,
file paths, class names, stack traces. A message like

    Duplicate entry 'chat_moderation_provider' for key 'uk_key'
    Query: INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)

names a table, a column set and an index to anyone holding a socket, and tells
the user nothing they can act on. It is a leak whether or not the frontend
renders it.

**An action exception declares safe text with `PersonFacingFailure`.** The marker
means its `getMessage()` is written for the person and safe to publish; it is
independent of the exception family. `ValidationException` carries it for its
children, including `TableActionException`. `ActionRateLimitedException`,
`ActionViewModeException` and `ActionImpersonationException` declare it while
remaining page exceptions, with their existing statuses, codes and `retryAfter`.
Keep a failure in its proper family; do not reparent it just to publish words,
and never mark diagnostic text as person-facing.

`ActionFailureReason::forClient()` applies that declaration to the tracked failure
ack, the untracked `ActionReply::sendException()` and an agent's action answer.
An unmarked failure gets `SignalConstants::ACTION_FAILED_REASON`; its diagnostic
class, message, file, line and trace stay in the server log. A reason a handler
writes directly for `sendActionFail()` is already person-facing text and owes the
same safety rule; this is not a way to pass `getMessage()` around the check.

**Page refusal words come from the server's status catalog.** Build
`subscription_page_error` through `PageSubscriptionErrorSignalData::forStatus()`:
`PageErrorMessages` supplies a `PageErrorText` with the required `title` and
`message`. It keeps the existing pairs for 400, 401, 403, 404, 500, 502, 503 and
504, and "Error" / "An error occurred." for any other status. Router, browser
and daemon producers use that same catalog. The frontend renders those fields;
`httpCode` and `errorCode` still govern behavior. A `PageSubscriptionException`
message remains a journal diagnostic, even if it names keys, ids or collections;
the page catalog does not publish it through the action marker.

For auth replies, the server's `AuthMessages` supplies a default only when no
explicit message was given. Preserve a received message through the selected
step and its replay. Where a reply or pending auth step declares a required
nullable `message`, the key is always present and `null` means a quiet outcome.
A missing or mistyped field is malformed, not an invitation to reconstruct
words from its code. The safe `message` on code-send progress is composed for
delivery/replay from the runtime view; provider detail remains a separate field,
not a substitute for that message. Legal consent likewise supplies its
unpublished/revised words, and a failed action's message survives a legal catch.

**Only three temporary local exceptions remain:**

- transport/timeout failures, including genuinely local malformed or browser
  outcomes for which no server refusal was received;
- the immediate admin 403 drawn while the server's access answer is in flight;
- the unbuilt-page 404 drawn without sending a subscription.

Keep the existing words in these cases. Put a `TODO` beside each local phrase
that requires removing it when server delivery supplies those words. A local
fallback never licenses discarding the words of a valid server refusal. This
rule changes the source of refusal copy, not its English wording or behavior;
it introduces no i18n mechanism and does not move ordinary UI/progress labels.

**Admin surfaces are the exception, and only for an actor who proved an admin.**
When the owner of the action is an `AbstractPage` whose `ACCESS_LEVEL` is
`PageAccessLevel::ADMIN`, and the connection the answer goes to proves an admin
on it at the moment of the answer (`PageAccessGate::provesAdmin()`) — not anyone
standing on an `ADMIN` page — the `action_error` frame carries two more optional
fields beside the generic reason: `errorType`, the failure's class name without
its namespace, and `errorDetail`, its original message. A viewer of the admin
view mode never gets them: its refusal is `errorCode` `view_mode` with the
server's safe reason, which the frontend displays
([../architecture/admin-view-mode.md](../architecture/admin-view-mode.md)). A
non-admin whose action the gate refused receives no diagnostic details either.
The SDK's `HilosActionError` draws the class name as text beside the icon of the
row's details button, and the original message in the details panel under the
caption "Exception", with Copy, so an operator can carry the real text into a
ticket. Three limits are part of the rule:

- they ride **only** on that admin frame to that actor; for any other caller the
  frame is byte for byte what it was before they existed;
- they are **absent for a `PersonFacingFailure`**, whose message is already shown
  in full: there is no withheld text to reveal, and the admin permission rule
  has not been relaxed;
- an action owned by an **agent** never carries them: the access-level guard
  judges a subscription to a page, and an agent has no page to be asked about.

### When the work outlives the reply

Some actions start work that cannot possibly finish inside the reply window: a
database dump, an import, a long report. Do not hold the action open for it — the
timeout is ~30s and the client would give up on work that is progressing fine.
Split the two:

- the tracked reply answers **acceptance** ("started", or "queued behind a running
  one") and releases loading at once;
- the **outcome** arrives later as its own addressed message to the connection that
  asked, carrying the reason on failure. The requester's accept key travels with the
  work — the agent keeps it alongside the run — and the agent addresses the notice
  back to that one connection when the run ends.

Unattended work — a schedule, a CLI run — has no requester and tells nobody. Its
outcome belongs in the feature's own durable record (a history row, a status field),
which is also where a human who arrives later reads it. Do not broadcast an
unattended failure to whoever happens to be connected.

## Signals (BE→FE)

Signals are backend-pushed messages: action replies (`::success` / `::fail`),
page and group data, and errors. A signal targets a connection, a group, or a
broadcast, resolved server-side via the `connId` map and group membership. Every
**page** signal carries its page key (see Subscriptions).

## Subscriptions (FE→BE)

Subscriptions come in two distinct kinds, kept separate because their
cardinality and lifetime differ.

### Page subscriptions (0..1)

A connection has **at most one** page subscription.

- **Open / navigate:** a single `page_subscribe` for the new page **atomically
  replaces** the current one (overwrite-on-resubscribe). Navigating is not a
  separate unsubscribe plus subscribe — that would send a redundant signal and
  open a subscribed-to-nothing gap.
- **Change params on the current page:** `page_update_subscription`. Tearing the
  subscription down and re-subscribing to change params is a violation.
- **Leave to no page:** `page_unsubscribe`.

The URL is the source of truth for the page subscription on cold load.

**A changed LEADER owner asks the existing tab to subscribe again.** The
framework control signal is
`{"type":"page_resubscribe","data":{"page":"<pageKey>","params":{}}}`.
`page` is a nonempty string and `params` is a required map, possibly empty.
It travels through the existing addressed `WS_USER`/`targetAcceptKey` delivery.
The built-in parser validates it before `HilosConnection` emits the typed
`pageResubscribe` event; a project schema cannot replace that control signal.

`PageSubscription` accepts it only for the current page and equal params
(map key order does not matter). It sends the ordinary `page_subscribe` with
the controllers' **current** `tableWindows`: filter, sort, limit,
anchor/direction or pageIndex, and rendered rows. The page scope, document,
socket and session stay in place. A stale request after navigation is ignored;
the existing session and protected-mode holds defer delivery until released.
The new owner applies the ordinary subscribe guards and answers with the same
one complete `page_response` below. No action or requestId is replayed.

The backend answers a subscription with a `page_response` signal carrying the
page key and the page scope payload:

```json
{"type": "page_response", "data": {"page": "<pageKey>", "payload": {"entities": {}, "data": {}, "lists": {}}}}
```

`page` lets the client drop a late response for a page it has left (see
[Page-signal routing by page key](#page-signal-routing-by-page-key)); `payload`
is a scope payload the normalizer ingests into the page scope. Its sections are
each optional and omitted when empty:

- `entities` — entity fragments by slot;
- `data` — the scope's plain scalars and blobs;
- `lists` — ordered list collections by list key. Each is
  `{"items": [{"itemKey": <key>, "slots": {…}}], "deleted": [<key>, …]}`, both
  arrays optional: a snapshot omits `deleted`, a delete-only delta omits
  `items`. A slot is an entity fragment (told apart by its `id`) or a plain
  value; the normalizer references the former and keeps the latter inline;
- `tables` — table row collections by table key, the same shape with `rows` in
  place of `items`. A row is
  `{"rowKey": <key>, "slots": {…}, "staleSources": [<sourceKey>, …]}`, where
  `staleSources` names the slots whose values have stopped being kept up to date
  and is **absent** on a row that is entirely current — which is nearly every
  row, so the ordinary case pays nothing for it. The same row envelope, with the
  same optional key, is what `table_window`, `table_viewport_delta`,
  `table_viewport_append` and `table_viewport_own_create` carry: a row reaches
  the client in one shape whichever frame brings it
  ([table-subscription.md](table-subscription.md)).

**A viewer of the admin view mode receives hidden values in these same shapes**
([../architecture/admin-view-mode.md](../architecture/admin-view-mode.md)). In
place of the value of a slot, of a field inside a slot or an entity fragment, or
of a key of `data`, the server writes the object `{"_hidden": true}`; a row's key
is never hidden, nor, in a typed table's row, its key field where it holds that
key. The frame of a page with a subscriber set of
its own keeps its signal name and its shape, with the same object in place of a
hidden value. A viewer's window may be served without the order or the search it
asked for — neither runs over a field hidden from them — and the window section
of the answer names the order that was served. A frame that core validates with
a schema accepts the mark wherever a field may be hidden (`hideable()`). The core
recognizes the object with `isHiddenValue` and hands the one `HIDDEN_VALUE` out
for a field a viewer's screen draws as hidden (`readHideableString`,
`readHideableBoolean`, `EntityCollection.hidden`).

**Every accepted subscription is answered exactly once**, by one `page_response`
carrying everything the page renders: the page's own part, its identity and its
browser part with the first window of each viewport table (HIL-1236). A page
that contributes no payload sends `page_response` with an empty one. The counts
beside a window's filters follow the answer as a frame of their own, because the
client has a window to put them on only once the answer opened it. A page re-sent
after a failed delivery is answered the same way, by the same one frame. The
frame is therefore an acknowledgement as much as a delivery — it is what the client waits
on before it shows the page (`router.pageLoading`), so that a page is never shown
and then taken away again by a denial still in flight. A subscription that is
refused answers with `subscription_page_error` instead, and the client waits on
that the same way; a page that answered neither would leave the client with
nothing to wait for.

**A frame a page sends under its own name before `page_response` is held for the
view that was not there yet.** The view is mounted only after the answer
releases the page, so a frame sent ahead of it has no listener at the moment it
lands. The client keeps the last frame of every `subscription_page_*` type and
replays it to a `projectSignal` listener that registers later, which is why such
a frame must carry the page's **whole** state and never a delta: a replayed
delta would double what was already delivered. `subscription_page_error` is the
one name under the prefix that is not held — a refusal is not a description of
the page, and replayed it would draw a ban the server has since lifted.

A page contributes the payload from the framework's `onSubscribe` via the
`buildPagePayload` hook, so a page never hand-rolls the signal. The `tables`
section rides the same envelope but the frontend consumes it last (the heavy
windowed primitive); see [data-model.md](data-model.md).

### Group subscriptions (0..N)

A connection holds **any number** of group subscriptions concurrently; each is
unsubscribed individually or all at once, and they **survive page navigation**.
Groups are how a subset of clients shares editable data — the canonical case is
a language-picker present on every page: an admin edits the languages and every
subscribed client updates. Page-subs and group-subs are never merged into one
abstraction.

### Page-signal routing by page key

Every page signal carries its page key. The normalizer (see [data-model.md](data-model.md))
applies a signal only to the matching current page subscription and **drops** any
signal for a page the client has left, so a late signal for a page you navigated
away from is never misapplied. There is no subscription generation / epoch token
— an A→B→A in-flight reorder does not occur in practice and is harmless if it
does (a fresh subscribe re-streams), whereas a token would add traffic and
complexity.

## Mandatory declared authorization

Every action and every page-subscribe carries a **declared** authorizer. The
dispatch and subscribe layer refuses anything with no authorizer attached, and
"public" is an explicit declared authorizer — never a silent default. This makes
"forgot to check server-side" impossible by construction; client-side hiding
stays pure UX (see [rules-and-violations.md](rules-and-violations.md)).

## File upload via frame_binary

Files are uploaded only over the WebSocket `frame_binary` channel. Uploading
through any other channel (HTTP multipart, etc.) is a gross violation.

With `HilosFeature::UPLOADS` the channel has a protocol of its own: the
tracked actions `hilos_upload_init` (declare a file) and `hilos_upload_cancel`,
every chunk signed `[1 byte L][L bytes clientUploadId][file bytes]`, and the
`hilos_upload_state` frame carrying the whole state of one upload (`phase: null`
= gone). Nonempty uploads also use the two credit control frames below.
The signed binary payload, init/cancel action contracts and upload-state frame
keep their existing shapes. Projects without `UPLOADS` keep their ordinary
`frame_binary` behavior. See [architecture/uploads.md](../architecture/uploads.md).
In the browser this protocol is spoken by `hilosUploads` from `@hilos/core`;
project code hands it a `File` and does not assemble action or binary frames.
See [The Browser Client](../architecture/uploads.md#the-browser-client).

### Upload credit

After the tracked init succeeds, the browser sends one text control request
for the **full declared raw file size**, not the next chunk size. Browser →
master, for a 200,000-byte file:

```json
{"type":"hilos_upload_credit_request","clientUploadId":"upload_1","bytes":200000}
```

These fields are top-level, with no `data` wrapper. This is not an action:
there is no `action`, `requestId` or action acknowledgement. The master stamps
`UploadCreditRequestSignalData` with the real WebSocket's internal `acceptKey`;
the browser neither supplies nor chooses it. A repeated demand must keep the
same full size, and the master checks the live upload's declared size,
receiving phase and owner before granting it.

Master → that browser connection, in the standard signal envelope:

```json
{"type":"hilos_upload_credit","data":{"clientUploadId":"upload_1","sendUpToBytes":65536}}
```

`sendUpToBytes` is an exclusive cumulative **raw file byte offset** from the
start of this upload on this connection. It excludes the upload-id signature
and WebSocket headers. The example permits offsets `[0, 65536)`. If 65,536
bytes have already been sent, a later boundary of 131,072 permits another
65,536; it does not grant an additional 131,072. The SDK keeps
`max(previousBoundary, receivedBoundary)`, so duplicate or older smaller
credits add no capacity. The signal has no page key or request correlation.

The client must not send binary bytes beyond that boundary. Before each
`File.slice()` it chooses
`min(65536, file.size - sentBytes, sendUpToBytes - sentBytes)` raw bytes.
With no remaining credit it waits for a credit event, without reading the
next slice. It also observes the existing socket `bufferedAmount` check.
A window may cover many chunks or the whole remaining file; this is not a
browser roundtrip per binary frame. An empty file needs no credit request,
reservation or credit wait: its ordinary init outcome is unchanged (the
current built-in `SizeLimitCheck` refuses it as `empty_file`).

Cancel, failure/gone, disconnect and a new connection/stream generation reset
the SDK's old permission and wake its waiters so the old stream stops. A
replacement socket declares eligible queued files again and requests fresh
credit. A gone/interrupted upload after owner loss does not carry its old
window to another owner.

The server validates signed raw input against current permission before
unbudgeted routing or worker/peer serialization, including admission of a
physical frame before waiting for its tail. Exhaustion withholds new upload
grants while worker, peer and control sockets continue to be read. Reservation,
processing receipts, shrink behavior and two-node ownership are defined once
in [The Raw-Byte Pool](../architecture/uploads.md#the-raw-byte-pool).

## Build-version check and forced refresh

A build timestamp lives in `.env` (`HILOS_BUILD_TIMESTAMP`, `dev` when unset),
is bumped at build time, and is carried in the `handshake` welcome frame — the
first frame the server sends on every connection. The SPA compares it on every
connect and reconnect; on mismatch it forces a page refresh to load the new
build. There is one global
build-version, not a per-message-type version — this handles a long-lived
no-refresh SPA outliving a backend redeploy.

A stale build is not the only thing that forces a reload: a node leaving
protected mode forces one too, for a different reason and on a different frame
(see [core-and-connection.md](core-and-connection.md)).

With a modal open and an unsaved draft, the refresh is not yanked out from under
the edit. The user keeps editing; a yellow warning by Save signals that a new
version is pending. The preferred path is a **"Refresh & keep my draft"** button
that serializes the open-modal descriptor and draft to localStorage, forces the
refresh, then rehydrates the modal and draft on load. The fallback is that a
successful save or closing the modal triggers the refresh. A restored draft is
re-judged by the backend under backend-only validation; credential-like fields
are never persisted (see [conflict-resolution.md](conflict-resolution.md)).

## Session-token rotation (HIL-582)

A login and any loss of the session's person rotate its token (HIL-1126), so its
previous value stops naming the session at either boundary. The new token can only
reach the browser through `Set-Cookie`, and the only frame that carries one is
the 101 — so it is traded for, not pushed:

1. the seam sends the initiating connection alone a framework
   `hilos_session_rotate` signal carrying a one-time `ticket`;
2. the client writes the ticket into an auxiliary cookie — the session cookie's
   name plus `_rotate`, `Path=/`, `SameSite=Strict`, 30 s, no `HttpOnly` (the
   session cookie itself is `HttpOnly`, which is why JS cannot write it) — and
   reconnects without the backoff pause;
3. the master reads that cookie on the next handshake, answers with the rotated
   token in the session `Set-Cookie`, erases the auxiliary cookie with a second
   one, burns the ticket, and drops the other connections of the old session,
   which come back into it carrying the new cookie. On a cluster it closes the
   sockets it holds and sends the remaining keys through `peer_connection_drop`
   to every other master (HIL-1306).

On sign-out, the requesting tab receives the ticket, or the first live tab when
the session was ended remotely. Its siblings learn they are anonymous before
that ticket leaves, and are dropped after the cookie exchange. With no live tab,
the session changes token without issuing a ticket. At handshake, expiry or block
gives the ticket to the arriving connection. A presented cookie naming no session
is replaced there too, except when a ticket cookie accompanied it: a lost ticket
must not create an endless replacement-ticket/reconnect loop.

**The reconnect waits for the replies the socket is still owed.** The rotation
signal is emitted from inside the action handler that logged in, so the action's
own `::success` is behind it on the wire; reconnecting on arrival would throw
away the answer to the click that caused the rotation. The client holds the
rotation until every outstanding `requestId` has been answered (or a 10 s grace
elapses), which is a guarantee rather than the hope that both frames land in one
batch.

**Continue waits for the current socket's page subscription.** After the socket
changes, Continue (`hilos_dismiss_session_ack`) waits until the existing
`PageSubscription` has actually sent the current page's subscription on that
socket. A `connected` state or `page_ready` remembered from the old socket does
not prove that. `runWhenSubscribed()` starts the action synchronously when the
subscription is sent, without an await between readiness and dispatch; it does
not wait for `page_response`.

One click enters the ordinary pending state and sends the action once when
ready. Leaving the page, closing or resetting the auth flow, or changing its
identity or scenario cancels a Continue that has not been sent. Established
connection unavailability ends pending with the existing error and permits a
retry. Canceling after dispatch does not undo the server mutation. This wait
belongs only to Continue; other auth actions and `ActionLifecycle` do not gain
a buffer. It adds no frames or fields to the wire.

A ticket that is unknown, expired, malformed or already spent is not an error:
the handshake is served by the ordinary cookie rule, and the auxiliary cookie is
erased all the same, so it is never presented twice. The session cookie name
rides the `handshake` welcome frame (`sessionCookieName`) because it is
deployment configuration, and without it the client cannot name the cookie it
has to write.

## Backend contract surface (the gate)

This protocol implies backend changes to the FE↔BE contract. Each passes the
Contract approval gate in [agents.md](../../../agents.md) before implementation,
listing the exact fields, signals, DTOs, and routes that change:

- the `httpOnly + Secure + SameSite` session cookie set at login/handshake, read
  from the handshake cookies; token-in-query retired;
- the server-side session store (`Hilos::$db` rows + `Hilos::$rt` presence),
  opaque-id keyed;
- the server-minted, socket-derived `connId`; the client-supplied acceptKey
  stops crossing to the client;
- the client-minted `requestId` echoed on `::success` / `::fail`, and since
  HIL-792 living on to the echo of the record the action writes;
- the addressed `table_viewport_own_create` signal: the author's own new row with
  the index it takes in that author's window, the new counts, the current boundary
  anchors, and the `requestId`
  of the press behind it;
- the page key carried on every page signal;
- the build timestamp carried in the `handshake` welcome frame;
- a mandatory declared authorizer on every action and page-subscribe.
