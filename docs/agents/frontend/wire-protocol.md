# Wire Protocol

The contract between the browser and the Hilos daemon: how a connection is
authorized, how messages are framed and parsed, and the three kinds of message
that flow over the socket. The runtime behavior that consumes this protocol
(state machines, authoritative-backend, loading UX) is in
`core-and-connection.md`; this document is the protocol itself.

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
  a gross violation (see `rules-and-violations.md`).
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
`core-and-connection.md`). No trust is remembered across sockets.

### The auth seam

The framework owns the auth machinery: cookie mechanics, the session store,
revocation, GC, the handshake protocol, and a default username/password login
flow. The project plugs in a credential verifier —
`HilosAuthProvider::verify(handshakeContext): SessionId | null` — and may
override the login flow. The framework calls the verifier at the handshake; on
success it establishes the session and binds it to the connection, on failure it
goes to `auth-failed`.

## Message envelopes and parsing

Every message is a tagged envelope. Signals are discriminated by a `type` /
`dataType` tag; actions carry an `action` name and a `data` payload. The action
envelope does **not** carry a connection id — the connection is the socket it
arrives on.

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

### Group subscriptions (0..N)

A connection holds **any number** of group subscriptions concurrently; each is
unsubscribed individually or all at once, and they **survive page navigation**.
Groups are how a subset of clients shares editable data — the canonical case is
a language-picker present on every page: an admin edits the languages and every
subscribed client updates. Page-subs and group-subs are never merged into one
abstraction.

### Page-signal routing by page key

Every page signal carries its page key. The normalizer (see `data-model.md`)
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
stays pure UX (see `rules-and-violations.md`).

## File upload via frame_binary

Files are uploaded only over the WebSocket `frame_binary` channel. Uploading
through any other channel (HTTP multipart, etc.) is a gross violation.

## Build-version check and forced refresh

A build timestamp lives in `.env`, is bumped at build time, and is carried in
the handshake response. The SPA compares it on every connect and reconnect; on
mismatch it forces a page refresh to load the new build. There is one global
build-version, not a per-message-type version — this handles a long-lived
no-refresh SPA outliving a backend redeploy.

With a modal open and an unsaved draft, the refresh is not yanked out from under
the edit. The user keeps editing; a yellow warning by Save signals that a new
version is pending. The preferred path is a **"Refresh & keep my draft"** button
that serializes the open-modal descriptor and draft to localStorage, forces the
refresh, then rehydrates the modal and draft on load. The fallback is that a
successful save or closing the modal triggers the refresh. A restored draft is
re-judged by the backend under backend-only validation; credential-like fields
are never persisted (see `conflict-resolution.md`).

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
- the build timestamp carried in the handshake response;
- a mandatory declared authorizer on every action and page-subscribe.
