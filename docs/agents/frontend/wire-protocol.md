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
- Non-secret UI state (theme, layout, drafts) goes in **localStorage**. Secrets
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
demos (chat, todo, poll) currently run a simplified **guest** model: the daemon
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
{"type": "handshake", "data": {"build": "<HILOS_BUILD_TIMESTAMP>"}}
```

`build` is the daemon's `HILOS_BUILD_TIMESTAMP` environment value (`dev` when
unset); it feeds the build-version check below.

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
Every action also produces a success/fail toast.

A state-changing action is **always tracked**: the initiator dispatches it
through this lifecycle and shows loading until it settles — it is **not**
fire-and-forget (a bare send with no correlated reply). When the action changes
shared or session state that other connections observe (a logout downgrading the
session, a rename fanning out), the personal `::success` only releases the
initiator's own loading; the **effect is broadcast as a signal** to every
affected connection, and each frontend reacts to that signal. Do not have one
connection mutate and assume the others discover it — send them the signal.

### A failure reason is a domain sentence, never an engine message

What travels on a `::fail` is a sentence about the thing the caller asked for:
"That name is already taken", "Backups are disabled". What must never travel is
the machinery underneath — driver text, SQL statements, index and column names,
file paths, class names, stack traces. A message like

    Duplicate entry 'chat_moderation_provider' for key 'uk_key'
    Query: INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)

names a table, a column set and an index to anyone holding a socket, and tells
the user nothing they can act on. It is a leak whether or not the frontend
renders it.

The framework enforces this at the edge: `PageSignalRouter` logs the exception in
full — class, message, file, line, trace — and sends the client a domain message,
substituting a generic one for any infrastructural failure (a `DatabaseException`,
or any non-`HilosException` fault). A handler that wants the user to read
something specific raises a domain exception whose message is written for them.

**Admin surfaces are the exception, and only behind the admin guard.** An
operator debugging a failing job may be shown the detail — an error column, an
expandable row, a log page. That is a deliberate, guarded feature, not the
default reply path: the same detail must never reach an end-user surface.

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
  value; the normalizer references the former and keeps the latter inline.

A page contributes the payload from the framework default `onSubscribe` via the
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

## Build-version check and forced refresh

A build timestamp lives in `.env` (`HILOS_BUILD_TIMESTAMP`, `dev` when unset),
is bumped at build time, and is carried in the `handshake` welcome frame — the
first frame the server sends on every connection. The SPA compares it on every
connect and reconnect; on mismatch it forces a page refresh to load the new
build. There is one global
build-version, not a per-message-type version — this handles a long-lived
no-refresh SPA outliving a backend redeploy.

With a modal open and an unsaved draft, the refresh is not yanked out from under
the edit. The user keeps editing; a yellow warning by Save signals that a new
version is pending. The preferred path is a **"Refresh & keep my draft"** button
that serializes the open-modal descriptor and draft to localStorage, forces the
refresh, then rehydrates the modal and draft on load. The fallback is that a
successful save or closing the modal triggers the refresh. A restored draft is
re-judged by the backend under backend-only validation; credential-like fields
are never persisted (see [conflict-resolution.md](conflict-resolution.md)).

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
- the client-minted `requestId` echoed on `::success` / `::fail`;
- the page key carried on every page signal;
- the build timestamp carried in the `handshake` welcome frame;
- a mandatory declared authorizer on every action and page-subscribe.
