# Page Access Control

Page subscriptions are authorized by **declarative guards** in the page's
`BROWSER` config. A guard is page data, not an interface or an authorizer object:
the same mechanism that enforces "does this resource exist" (404) also enforces
"may this connection see this page" (403).

This is the low-level guard mechanism. A future role/permission system (RBAC)
sits on top of it; it does not replace these guards.

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
  on `SOURCE` (e.g. `User::admin`), otherwise 403. Checks the *subscriber*.

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

## Error codes

A guard failure throws a `PageSubscriptionException` subclass, which is caught and
sent to the client as a `subscription_page_error` signal:

- 404 `not_found` — `PageResourceNotFoundException`: the resource is missing.
- 403 `forbidden` — `PageForbiddenException`: authenticated but lacks rights.
  This is the admin gate — a guest is authenticated by its cookie, just not admin.
- 401 `unauthorized` — `PageUnauthorizedException`: not authenticated at all
  (reserved for a real login; not raised in the guest demos).

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
