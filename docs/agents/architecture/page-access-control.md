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
user's tabs are TOLD who they now are, next to the handshake re-send that already
tells them what they may now show — the project's handler of the
`hilos_session_state` frame, described in full below. A rights change gets there
the way every other identity change does: `AbstractSessionsLibraryAgent` answers
the grant command, writes the flag through the project's `applyAdminGrant` seam
and then restates every live session of that person (`announceAdminGrant`). So a
project mounting the framework grant gets the re-decision by inheritance and has
no call site of its own to start (HIL-729).

`PageAccessReassessment::forUser($userId)` queues one `page_access_reassess_user`
announcement naming that person and returns. Each worker of the node then runs
`PageAccessReassessment::sweepThisWorker($userId)` over its own live page
subscriptions, reads each accept key through `BrowserContext::connectionIdentity`,
and queues one `page_access_reassess` frame per page that user has open there. Each
frame is routed exactly like the subscribe it re-judges — to the page's own agent, by
the same page→agent resolution — and answered by the same code, so allow sends a full
`page_response` and deny sends the `subscription_page_error` the same verdict would
have produced at subscribe. One path, one shape on the wire.

**Announcing and sweeping are two steps because they live in two processes** (HIL-644).
The pages of one person are spread across every worker of the node, while who is behind
a connection can be answered only where a browser context is mounted — in a worker. So
the writing worker announces, the master fans the announcement out to every worker link,
and each worker sweeps the mirror it owns. The re-decision therefore reaches every open
page of that person, wherever it is served, and not merely the pages of the worker that
happened to write the rights.

The master's part is one buffered write per worker and nothing else: it resolves no
identity, walks no registry, and holds no reverse "connections of user X" lookup — that
would be a second source of truth beside `BrowserContext::connectionIdentity`. The
announcement is queued in the master rather than acted on at receipt, because the
database sync of the flag that was just written rides the same queue in front of it; a
frame acted on at receipt would overtake the sync and set a worker re-deciding against a
flag it has not seen change.

**A downgrade is the second trigger, and it carries its own criterion** (HIL-652).
Signing out is not a rights change: nothing is written about what the person may
see, the session simply stops being theirs. The by-user announcement cannot serve
it, and not for want of plumbing — a downgrade REMOVES the identity that criterion
matches on. Announced after the runtime write that un-points the connections,
"the pages of user N" matches nothing; announced before it, the pages match but
are judged with the identity being destroyed, so the sweep answers allow and
re-sends the privileged page. Worse than doing nothing.

So the criterion is the accept keys, which the operation holds directly and which
name the same sockets on both sides of that write.
`PageAccessReassessment::forConnections($acceptKeys)` queues one
`page_access_reassess_connections` announcement, and each worker runs
`PageAccessReassessment::sweepThisWorkerConnections($acceptKeys)` over its own
mirror — never asking `BrowserContext::connectionIdentity` anything, which is
exactly what lets it outlive the write. It is a frame of its own beside the by-user
one rather than a payload carrying either, because they are two different
questions and a merged shape would make every receiving worker first establish
which of the two it is holding.

**The seam is one frame, `hilos_session_state`,** and the project handler that
applies it — `ChatAgent::applySessionState()` and its twin in every other project.
Since HIL-710 the session itself belongs to `AbstractSessionsLibraryAgent`, which
runs in a process of its own and cannot write the connection rows: it concludes
what the session has become and says so in that one frame, whatever brought it
about — the shell sign-out, the session-expiry drop, the account-merge force-logout,
the recovery drop of the other sessions. So all four still behave identically, but
through one frame rather than through one method, and the announcement is made by
the project because the rows it is judged against are the project's.

**A project writes that handler once and never announces anything again.** Inside
it the announcement is queued ONCE after the loop over the frame's accept keys, not
per connection (the frame already reaches every worker), and AFTER the runtime
writes rather than before: both travel one FIFO, so every worker applies "this
connection belongs to nobody" before it re-judges. The same handler picks the other
criterion when the frame names a user — a sign-in ADDS the identity, so
`forUser($userId)` is the one that matches there.

**The reach is node-local, and that is the whole operation's reach.** The other half of a
rights change — the handshake re-send — is delivered by this node's WebSocket server, so a
tab on another node never learns of the grant, never waits for an answer, and keeps the
honest 403 it already had. Making the pair cross-node is one subject, not half of one.

**A re-decision is not a re-subscribe.** The frame never passes through
`DaemonManager::updateSubscriptions`, because a real re-subscribe carries three
side effects that have no place here: the registry entry would be rewritten, the
connection's table viewports would be dropped ("a (re)subscribe reloads the page"),
and the visit would be billed again in analytics. The subscription does not change;
only the answer to it does.

**Pages a rights change cannot move are skipped** — PUBLIC level and no browser
guards. Otherwise granting admin would push a full page answer into every open chat
tab of that person: harmless frames, pure noise.

That skip is also what a project's own admin page must not fall into. A page left
PUBLIC while its route carries `admin: true` is re-decided by nobody, yet the
client reaction below reads the marker and waits for an answer that never comes —
so signing out leaves the tab waiting instead of showing the sign-in invitation.
The marker states surface type; the closure is the page class's own
`ACCESS_LEVEL` (or its guard). See
[admin-features.md](admin-features.md#closing-a-projects-own-admin-page).

**The frontend reacts ahead, the server's answer rules.** `bindAccessReaction`
watches two facts the handshake response carries — the admin marker and the person
— and splits on which of them moved:

- **the marker falls, the identity stands:** draw the 403 and drop the page data at
  once, on an administrative route. Stale privileged rows must not survive one
  frame while the server's verdict is on the wire.
- **the identity goes** (HIL-652), on an administrative route: drop the page data
  and wait for the answer, drawing nothing. 403 says "not for you", while the true
  answer for somebody who just signed out is the 401 invitation the server is
  already sending — and drawing a verdict known to be wrong while the right one is
  in flight buys nothing.
- **the marker is gained** while a 403 is displayed: return the page to its
  just-navigated state.
- **anything else,** including every non-administrative route: no reaction at all.
  There is no "needs a signed-in visitor" marker, so /profile and a guarded project
  page are closed by the server's answer alone.

The client sends nothing in any of them: it never rules on access, and a 403 it
drew wrongly is overruled by the first `page_response` that arrives. The surface
test is the route's own `admin` marker, which states surface *type* rather than
required rights, which is why it is deliberately not the only defense.

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

This section is one instance of a general obligation:
[../signals/screen-invalidation.md](../signals/screen-invalidation.md).

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
