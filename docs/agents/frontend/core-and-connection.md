# Core and Connection

How the Hilos frontend behaves as a running application: the no-refresh SPA
model, the state machines that govern it, and the authoritative-backend rule
that decides when state may change. This is the runtime foundation the other
topic documents build on.

## The no-refresh SPA

The whole application is a single page driven over one WebSocket connection. It
never does a full-page reload during normal use; it stays continuously
self-updating to the current backend state. Because almost everything is
deferred-loaded, **there is a placeholder for nearly every piece of data** — a
view renders its structure immediately and fills each region as data arrives
(see the per-datum state machine below and the tier-1 skeleton / empty / error
components in [sdk-packaging.md](sdk-packaging.md)).

There is an explicit, separate behavior when there is **no** connection — never
an undefined or frozen state. What that behavior is per view is a project choice
(see Disconnect behavior).

## Three orthogonal state machines

Connection state is not one flat machine. Three concerns are modeled
separately, layer in one direction, and **fail independently**.

### Connection — transport liveness

`connecting → connected → reconnecting → disconnected`. Purely about the socket.
A dropped socket moves to `reconnecting` and retries with exponential backoff
and jitter; it does not by itself invalidate identity.

### Authentication — identity, per session

`authenticating → authenticated → auth-failed`. Identity is established at the
handshake from the cookie credential and re-established on every new socket,
including reconnects — transparently, unless the credential is missing, expired,
or revoked. An `auth-failed` outcome surfaces **globally** (a login or "session
expired" screen), because it concerns the whole session. The handshake, the
cookie credential, and the server-side session store are specified in
[wire-protocol.md](wire-protocol.md).

### Authorization — permission, per resource

`allowed / forbidden`, evaluated per page, action, or resource, and dynamic. An
authorization denial is **localized**: the affected page or action renders an
explicit "no access" in place — it is never a disconnect and never a logout.
Authorization verdicts arrive as backend signals (a denied subscribe, or an
action `::fail` with an authz reason), consistent with the authoritative-backend
rule below.

### How they relate

They layer in order — connection, then authentication, then authorization — but
a failure at one layer does not cascade into the others:

- socket drop → `reconnecting`; identity remains valid → re-auth is transparent;
- `auth-failed` → global login / "session expired", regardless of connection;
- authorization-denied → localized "no access" on that one page or action only.

Authentication answers *who* you are (one identity per session); authorization
answers *what you may do* with a concrete resource (one verdict per
page/action). They are never merged into a single "logged-in" flag.

## The per-datum state machine

Every datum a view shows carries a state, not just a value:
`unknown → loading → loaded → stale → error`. This is what "a placeholder for
everything" means concretely — the universal skeleton/placeholder system is tied
to this state, not bolted on per component. `stale` is a real internal state
(see Stale data), and the normalized entity store that holds loaded values is
specified in [data-model.md](data-model.md).

## A page shows a placeholder until its subscription answers

A view never renders page content before its page subscription has replied. Until
the reply lands — a data snapshot, or a guard result such as the AUTHENTICATED
401 — the view shows a **placeholder** (a skeleton or a short loading line), never
the real content and never a surface built on an assumed outcome. Rendering early
races the reply: a second, throwaway UI mounts and is swapped the moment the
answer arrives, discarding any state it gathered — the profile sign-in form that
`reset()` mid-input when the 401 landed was exactly this. One reply, one owner of
what shows: the placeholder gives way to content on a snapshot, or to the
auth-gate surface on a 401 — the gate, not the page, owns the sign-in form.

## Authoritative backend — no optimistic updates

This is the keystone rule of the runtime. Every user action does two things:

1. the UI immediately enters a loading or blocked state (the button or form
   shows loading);
2. a signal goes to the backend.

Frontend state then mutates **only** when a backend signal says so — never
optimistically on the client, **not even for the user's own edits** (they render
only once the backend echoes them). The backend is the single source of truth
for every state transition. This removes the entire class of optimistic-rollback
bugs and pairs with the universal loading state and the placeholder model.

The one edge to handle deliberately: an action whose reply never arrives (the
socket dropped mid-action). The UI must exit its loading state via the action
timeout, tied to the Connection machine — the form never hangs forever. The full
action lifecycle (deferred ~0.3s loading, `requestId`-correlated reply, ~30s
timeout, orphan reconciliation) is in [wire-protocol.md](wire-protocol.md). Every action also
produces a success/fail toast (catalogued in [rules-and-violations.md](rules-and-violations.md)).

## Disconnect behavior

The framework provides **both** disconnect modes, and the project picks per view
— there is no single global policy:

- **read-only last-known** — keep showing the last data, disable mutation;
- **hard block** — overlay the view and prevent interaction until reconnected.

A long-lived view that is safe to keep visible favors read-only; a view where
acting on stale data is dangerous favors hard-block.

## Stale data

When on-screen data may be stale — the connection dropped, or a nudge was
received but not yet refetched — the standard UX is a **single global banner**.
Do **not** mark each stale datum individually; `stale` stays a real per-datum
state internally, but per-element stale marks are not the Hilos standard.
Projects and components may add extra connection-status indicators (not
forbidden). And never show staleness **timestamps** ("synced 3 min ago"): with a
connection the data is current, so a timestamp only deceives — not-current is
surfaced only by the banner.

## Identity-driven rendering

- **Do not render role-gated chrome before identity resolves.** Until the role
  (guest / user / admin / …) is known, do not paint role-dependent UI; once it
  resolves — which happens just before first paint and on every navigation —
  render immediately. Public pages render immediately regardless.
- **Auth-gated routing is a content-swap, not a redirect.** A guest landing on a
  non-guest page renders the auth form in place (it morphs by state: login /
  register / recover); a logged-in user lacking rights renders "no access" in
  place. The URL is the source of truth for the page subscription on cold load.
  The subscription mechanics are in [wire-protocol.md](wire-protocol.md).

## SSG and the SPA shell

The authenticated, real-time area is a pure SPA shell (skeletons fill it as data
streams). A public, SEO-relevant surface is statically prerendered. The build
supports both; the prerender path and the env/test matrix are in
[build-and-docker.md](build-and-docker.md).
