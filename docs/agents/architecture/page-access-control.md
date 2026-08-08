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
  `onSubscribe`, so no page payload leaves ahead of the check. The denial is a
  `PageSubscriptionException`, so the subscription stays alive for
  live-promotion (sign-in / admin grant resumes delivery without re-subscribe).
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
hook bridges it. The framework default denies:

```php
// BrowserContext — no acceptKey→user map exists at framework level
protected function resolveCurrentUserId(string $acceptKey): ?int
{
    return null;
}
```

The project overrides it on its `BrowserContext` to read its own RT identity:

```php
// ChatBrowserContext
protected function resolveCurrentUserId(string $acceptKey): ?int
{
    try {
        return Hilos::$rt?->connections[$acceptKey]?->userId;
    } catch (Throwable) {
        return null;
    }
}
```

`null` → the connection is treated as unauthenticated for the guard → 403. The
admin-flag read itself is not new code: the `ACCESS` guard reuses the guard
source mechanism (`SOURCE` = users, the resolved id, `FIELD` = admin).

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

## Guards run on EVERY delivery path

A guard checked only at subscribe leaks: reactive and viewport updates would
still reach a rejected subscription. Guards are re-checked on every path that
delivers page data:

- **initial snapshot** — `subscribeSnapshot` asserts the guards (throws → error
  signal).
- **reactive fan-out** — `emitBrowserSignals` calls the non-throwing
  `pageGuardsAllow()` per accept key (memoized for the pass) before delivering.
- **viewport** — `sendTableWindow` runs the same check before sending a window.

While a guard fails, the fan-out delivers **nothing** to that connection.

## Preserve-on-fail and live-promotion

A failed guard does **not** tear the subscription down — preserving it is
intentional. It enables *live-promotion*: open `/user/10` when only 9 users exist
→ 404 ErrorPage; user #10 is then created → the same live subscription resumes
delivering on the backend with no re-subscribe. The access case is symmetric: a
guest on a gated page is granted the flag → delivery resumes.

This rules out both tearing the subscription down and a static "guard passed" tag
set once at subscribe. The guard is re-evaluated dynamically on each delivery
(above), so the instant it passes, delivery resumes.

The backend half ships today: the moment a guard passes, the fan-out delivers
again. Making the promotion *visible* also needs the frontend to clear the error
view when real data arrives — that frontend piece is not yet wired, so a promoted
page still needs a navigate/refresh to drop the error view until it is.

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
full-page error view (by `httpCode`) in place of the page, cleared on navigate.
Demos need no change — the error view lives in the SDK outlet.

Granting the flag an `ACCESS` guard checks (e.g. making a user admin) is a
control-plane operation — see [command-server.md](command-server.md). For the
subscription signals these errors travel on, see
[../signals/subscriptions.md](../signals/subscriptions.md).
