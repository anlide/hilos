# Page Access Control

Access to a page is controlled by two layers, checked in this order:

1. **The page access level** (`ACCESS_LEVEL` constant, `PageAccessLevel` enum) —
   a hard gate every page carries. The framework admin surface is **closed by
   default**: `AbstractHilosPage` inherits `ADMIN` to all of its descendants,
   and openness is an explicit declaration on the page class.
2. **Declarative browser guards** in the page's `BROWSER` config — resource and
   subscriber checks a page opts into (`DB_EXISTS`, `ACCESS`, `AUTHENTICATED`).

A guard is page data, not an interface or an authorizer object: the same
mechanism that enforces "does this resource exist" (404) also enforces "may this
connection see this page" (403).

This is the low-level mechanism. A future role/permission system (RBAC) sits on
top of it; it does not replace these layers.

## Page access levels (the closed-by-default gate)

Every page class declares (or inherits) `ACCESS_LEVEL`, a `PageAccessLevel`:

- `PUBLIC` — no identity check. The `AbstractPage` default, so project pages
  keep the anonymous-read model unless they declare otherwise.
- `AUTHENTICATED` — the connection must resolve to a user, else 401.
- `ADMIN` — the user must also pass the project's `isAdmin()` seam, else 403
  (401 when anonymous). The `AbstractHilosPage` default: every framework admin
  page is closed unless it explicitly relaxes its level.

The framework relaxations are pinned by an exact-composition unit test
(`PageAccessLevelRegistryTest`): `PUBLIC` = About/Terms/Privacy/License,
`AUTHENTICATED` = Profile/Notifications, everything else `ADMIN`. Note
`AbstractHilosProfilePage` extends `AbstractPage` directly (it is served by the
project agent, not the admin agent), so its `AUTHENTICATED` declaration is
mandatory — silence would open the profile to guests.

`PageAccessGate` is the single carrier of the rule and is enforced at:

- **subscribe** — `PageSignalRouter::dispatchPageSubscribe`, *before*
  `onSubscribe`, so the page builds no payload for a session that will be
  refused. The freeze, the route params and the page's own browser guards follow
  in the same breath, through `BrowserContext::assertSubscriptionAccess()`. The
  denial is a `PageSubscriptionException`, so the subscription stays alive for
  live-promotion (sign-in / admin grant resumes delivery without re-subscribe).
  The update path (`dispatchPageUpdateSubscription`) is judged the same way, on
  the params the update would leave the subscription holding.
- **every delivery** — `BrowserContext::assertPageGuards`, after the
  protected-mode lockdown and before the browser guards, which starves a denied
  kept-alive subscription of fan-out and table windows. This second point is
  load-bearing: most admin pages declare no browser guards at all.
- **actions** — `PageSignalRouter::dispatchAction`, before `AUTH_ACTIONS`: a
  guest gets 401 `ActionUnauthorizedException`, an authenticated non-admin 403
  `ActionForbiddenException`. A page's actions are closed by its level; the
  per-action `AUTH_ACTIONS` list remains for project pages with the
  anonymous-read + authenticated-write model.

Identity comes from two `BrowserContext` seams (see the identity hook below):
`resolveActionUserId()` answers "which user", and `isAdmin(int $userId): bool` —
framework default `false` — answers "is that user an admin". A project without a
mounted browser context fails **closed**: nothing resolves, the surface denies.

## Declaring a guard

Guards live under `BrowserConfigKey::GUARDS` in a page's `BROWSER` const — a list
of maps, each keyed by `BrowserGuardKey`:

```php
public const array BROWSER = [
    BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS,
    BrowserConfigKey::GUARDS => [
        [
            BrowserGuardKey::TYPE => BrowserGuardType::ACCESS,
            BrowserGuardKey::SOURCE => ChatBrowserSource::DB_USERS,
            BrowserGuardKey::FIELD => User::admin,
        ],
    ],
];
```

Guard types (`BrowserGuardType`):

- `DB_EXISTS` — the route-param `KEY` must resolve to a row in `SOURCE`,
  otherwise 404 (or 403 if `ERROR` says so). Checks a *resource*.
- `ACCESS` — the subscribing connection's current user must hold a truthy `FIELD`
  on `SOURCE` (e.g. `User::admin`), otherwise 403 (401 when anonymous). Checks
  the *subscriber*.
- `AUTHENTICATED` — flagless: the connection must resolve to a user at all,
  otherwise 401. For a page whose only requirement is "signed in", prefer
  declaring `ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED` instead — one access
  rule on the class beats a parallel guard entry (the former framework users of
  this guard type migrated to the level in HIL-441).

Guard keys (`BrowserGuardKey`): `TYPE`, `SOURCE`, `KEY` (DB_EXISTS route param),
`FIELD` (ACCESS flag column), `ERROR` (which code to raise on a miss).

## Who the subscriber is — the identity hook

`DB_EXISTS` checks a resource by a route param and needs no identity. `ACCESS`
must know *which user* is behind the WebSocket accept key — and that mapping is
project-owned state (RT `connections`) the framework cannot see. One protected
hook bridges it, and it answers **three** states rather than two: this user, no
user at all, or not known yet. The framework default is a settled "nobody":

```php
// BrowserContext — no acceptKey→user map exists at framework level
protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
{
    return ConnectionIdentity::resolved(null);
}
```

The project overrides it on its `BrowserContext` to read its own RT identity:

```php
// ChatBrowserContext
protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
{
    try {
        $connection = Hilos::$rt?->connections[$acceptKey];

        return $connection === null
            ? ConnectionIdentity::pending()
            : ConnectionIdentity::resolved($connection->userId);
    } catch (Throwable) {
        return ConnectionIdentity::resolved(null);
    }
}
```

`userId === null` on a settled answer → the connection is treated as
unauthenticated for the guard → 403. The admin-flag read itself is not new code:
the `ACCESS` guard reuses the guard source mechanism (`SOURCE` = users, the
resolved id, `FIELD` = admin).

**Why the third state exists (HIL-599).** The connection is identified by the
agent that owns its WebSocket, in *that* agent's worker, while the guards run in
whatever worker serves the page — with the RT sync in between. A frame arriving
inside that window used to read as a guest and be answered 401, so a signed-in
person reconnecting saw "sign in". A missing row now means *pending*, and
`PageSignalRouter` parks the frame — page subscribe, action, table viewport
alike — in a per-connection FIFO queue instead of judging it, sweeping the queue
on the worker tick once the row lands. After 500 ms of waiting the frame is
judged exactly as it would have been without the queue, so nothing here can make
a connection worse off than before. A storage failure stays a settled "nobody"
on purpose: a broken registry must close access, not suspend it.

`resolveCurrentUserId()` is `final` — it is now the flattened reading of this
seam (`->userId`), not an override point, so a project has one identity source
and not two that can disagree.

The **page access level** uses a second seam next to it: `isAdmin(int $userId):
bool`, framework default `false` (deny). The project answers it from its own
user storage (e.g. the chat demo reads `Hilos::$db->users[$userId]?->admin`),
defensively — any storage failure denies. Unlike a declarative `ACCESS` guard,
the seam runs in whatever worker serves the page, so it must read a source that
is readable there; when a central authorization hook lands (HIL-309), only this
method's body changes.

## Error codes

A guard failure throws a `PageSubscriptionException` subclass, which is caught and
sent to the client as a `subscription_page_error` signal:

- 404 `not_found` — `PageResourceNotFoundException`: the resource is missing.
- 403 `forbidden` — `PageForbiddenException`: authenticated but lacks rights.
  This is the admin gate — a guest is authenticated by its cookie, just not admin.
- 401 `unauthorized` — `PageUnauthorizedException`: not authenticated at all.
  Raised by the `AUTHENTICATED`/`ADMIN` access levels and the `AUTHENTICATED`
  guard; the frontend mounts the in-place sign-in surface over the page.
- 500 `internal_error` — `PageInternalErrorException`: **not** a guard verdict.
  The declaration itself is broken — a `SIGNAL` that is not a name, a guard of an
  unknown `TYPE`, an access guard with no `FIELD`, a source naming no collection.
  Nothing the subscriber did produces it and no sign-in clears it; it is the
  page's own `BROWSER` const that has to be fixed. It is reported rather than
  read as "this page has no browser data", the silence that used to make a typo
  indistinguishable from a plain page.

## Guards run on EVERY delivery path

A guard checked only at subscribe leaks: reactive and viewport updates would
still reach a rejected subscription. Guards are re-checked on every path that
delivers page data:

- **subscription request** — `PageSignalRouter` asserts the guards through
  `assertSubscriptionAccess()` before the page builds anything (throws → error
  signal). It runs on the request and not inside the snapshot, so it also covers
  a page that declares no browser config and one that declares guards but names
  no browser signal — both of which the snapshot returned early on, leaving the
  freeze to the client's own placeholder.
- **reactive fan-out** — `emitBrowserSignals` calls the non-throwing
  `pageGuardsAllow()` per accept key (memoized for the pass) before delivering.
- **viewport** — `sendTableWindow` runs the same check before sending a window.

While a guard fails, the fan-out delivers **nothing** to that connection.

A broken declaration is skipped the same way, but for one subscription only: the
fan-out catches `PageInternalErrorException` around each subscription's turn,
logs it, and moves on to the next; `sendTableWindow` does the same around the
window it was asked for. Those traps are not tidiness — both paths are
dispatched bare (nothing between them and the worker's `exit`), and a
declaration is static, so without them one mistyped `BROWSER` const would take
the worker down on every flush or window request and `ensureMinWorkers` would
restart it straight back into the same one, with every other subscriber on that
worker as collateral. Only the subscribe path lets it through, and on purpose:
there it reaches the client as the 500 above, which is the one place a person
sees it.

## Preserve-on-fail and live-promotion

A failed guard does **not** tear the subscription down — preserving it is
intentional. It enables *live-promotion*: open `/user/10` when only 9 users exist
→ 404 ErrorPage; user #10 is then created → the same live subscription resumes
delivering on the backend with no re-subscribe. The access case is symmetric: a
guest on a gated page is granted the flag → delivery resumes.

This rules out both tearing the subscription down and a static "guard passed" tag
set once at subscribe. The guard is re-evaluated dynamically on each delivery
(above), so the instant it passes, delivery resumes.

Both halves ship today. On the backend, the moment a guard passes, the fan-out
delivers again. On the frontend, a `page_response` for the current page clears the
error view (`PageSubscription.ingestPageResponse`), so the promoted page replaces
the error surface where the visitor is standing, with no navigate and no refresh.

**The 401 is the one status data does not lift.** It is not a denial waiting to be
overruled — it is the auth gate holding the page while the person identifies
themselves, and it comes down by `HilosRouter.clearPageError` from the gate's own
resume (`createAuthGate`), never by an answer. The gate postpones that resume
while the session owes an ack (HIL-422), and an answer clearing the 401 inside
that window would pull the sign-in surface out from under the panel and show a
page nobody has acknowledged. The payload is ingested all the same, which is why
the resume costs no round trip: the page is already in the scope when the error is
lifted off it.

## Re-deciding an OPEN page when rights change

Live-promotion answers "the guard starts passing". It does not answer the moment
the *verdict itself* changes: the access verdict is reached once, at subscribe,
and afterwards only re-checked as a gate on delivery. So a revoke used to leave
privileged content on screen and a grant used to leave a 403 there, both until the
person reloaded. Re-deciding closes that (HIL-621).

**The announcement is part of the operation.** The sweep is started where the
rights are WRITTEN, next to the handshake re-send that already tells the user's
tabs what they may now show:

- the framework starts it itself in `AbstractHilosIndexAgent::handleAdminGrant`,
  right after the project's `applyAdminGrant` returns, so every project mounting
  the framework grant gets it by inheritance;
- a project routing its own grant command starts it there — the chat demo's
  `ChatAgent::handleSetAdmin` is the worked example.

`PageAccessReassessment::forUser($userId)` walks the live page subscriptions, reads
each accept key through `BrowserContext::connectionIdentity`, and queues one
`page_access_reassess` frame per page that user has open. Each frame is routed
exactly like the subscribe it re-judges — to the page's own agent, by the same
page→agent resolution — and answered by the same code, so allow sends a full
`page_response` and deny sends the `subscription_page_error` the same verdict would
have produced at subscribe. One path, one shape on the wire.

What the sweep WALKS is the subscription mirror of the worker it is called in — the
same worker-local mirror the browser fan-out uses — so it reaches the pages served
by agents in that worker. Delivery is global, enumeration is not: start the sweep
from the agent that writes the rights, and expect it to cover the pages that
agent's worker serves.

**A re-decision is not a re-subscribe.** The frame never passes through
`DaemonManager::updateSubscriptions`, because a real re-subscribe carries three
side effects that have no place here: the registry entry would be rewritten, the
connection's table viewports would be dropped ("a (re)subscribe reloads the page"),
and the visit would be billed again in analytics. The subscription does not change;
only the answer to it does.

**Pages a rights change cannot move are skipped** — PUBLIC level and no browser
guards. Otherwise granting admin would push a full page answer into every open chat
tab of that person: harmless frames, pure noise.

**The frontend reacts ahead, the server's answer rules.** `bindAccessReaction`
watches the admin marker the handshake response carries. Losing it while the
current route is administrative draws the 403 and drops the page data at once —
stale privileged rows must not survive one frame while the server's verdict is on
the wire. Gaining it while a 403 is displayed returns the page to its
just-navigated state. The client sends nothing: it never rules on access, and a
403 it drew wrongly is overruled by the first `page_response` that arrives. The
surface test is the route's own `admin` marker, which states surface *type* rather
than required rights, which is why it is deliberately not the only defense.

Order does not matter and is not synchronized. Handshake before the answer: the
client shows waiting and the answer ends it. Answer before the handshake: the data
cleared the error by itself. A server 403 landing on a 403 the client already drew
is the same state. All four orders converge, so there is no queue and no frame
version.

**The limit, written down rather than engineered away.** A rights change nobody
announced — a flag written straight into the database, or a future grant path that
forgets the call — is not noticed. Detecting it would mean re-deciding every live
subscription's verdict on every tick: a cost paid always, against a case that is
code which already owes the announcement.

## The cross-agent guard rule

**An `ACCESS` guard must sit on a page whose subscribing agent OWNS — not mirrors
— the identity sources the guard reads.**

The reactive guard re-check runs inside the agent that fans out that page. If
that agent reads the user/connection identity through a *cross-agent mirror* (a
lagging copy synced from another agent), the guard can flicker to 403 mid-session
on a stale read — flashing the 403 ErrorPage over live content.

Worked example: gating a page served by an agent that only *mirrors* the
users/connections owned by the chat agent flickered to 403 during edits. Moving
the gate to a page served by the chat agent — which OWNS users and connections —
resolves identity authoritatively in-process, with no race. When you place an
`ACCESS` guard, confirm the page's `SUBSCRIPTION_AGENT_TYPE` agent is the truth
source for the `SOURCE` the guard reads.

## Frontend

The `subscription_page_error` signal is consumed at the core level and renders a
full-page error view (by `httpCode`) in place of the page, cleared on navigate and
by the next `page_response` for that page. Demos need no change — the error view
lives in the SDK outlet, and `bindAccessReaction` is wired by `bootHilos`.

**A refused page holds no data**, whoever refused it: the page scope is re-opened
along with the error, not merely covered by it. That matters because the page scope
is the most specific layer of entity resolution — a users-table row carrying
`admin: true` outranks the session's own `admin: false` — and a refused subscription
receives no fan-out to correct the copy it is holding. Before the re-decision every
refusal arrived moments after a subscribe, onto a page scope that had just been
opened and was empty, so this costs those paths nothing.

Granting the flag an `ACCESS` guard checks (e.g. making a user admin) is a
control-plane operation — see [command-server.md](command-server.md). For the
subscription signals these errors travel on, see
[../signals/subscriptions.md](../signals/subscriptions.md).
