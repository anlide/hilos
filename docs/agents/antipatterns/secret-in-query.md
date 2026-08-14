# Anti-pattern: A Secret in the Query String

Read this before reading a value out of a url's query string, before choosing
where a client should present a session token, key or signed state, and whenever
the `SECRET-IN-QUERY` guard fails on a call you added.

## Core Rule

A secret does not travel in a url. Not the session token, not an API key, not a
signed state, not a one-time code — nothing whose possession is what grants
access.

A secret has exactly two carriers, and which one you use follows from who the
client is:

| Client | Carrier |
|---|---|
| a browser | a cookie the daemon set, `HttpOnly` and `SameSite=Strict` |
| anything else | a request header the client sets itself |

The browser has no choice in the matter: it cannot add a header to a navigation,
to an `<img>`, or to the WebSocket upgrade its own API builds. That is precisely
why the cookie exists, and why reaching for the url instead is the tempting wrong
answer rather than an obviously wrong one.

## Why

A url is not part of the private conversation between client and server. It is
the label on the conversation, and it gets copied:

- **Proxy and server logs.** An access log line is the request line, query string
  and all. Every hop keeps one, usually for longer than anybody remembers, and
  usually somewhere with a broader readership than the database.
- **Browser history.** It survives the session, the tab, and the logout, on a
  machine that may not be the visitor's own.
- **`Referer`.** A page loaded with a secret in its url hands that url to
  whatever it loads next, including third parties.
- **Anything that treats a url as a name.** Bookmarks, chat previews, crash
  reports, "copy link", a screenshot of the address bar.

None of these is a bug to be fixed. They are all correct behaviour applied to a
value that should never have been there. A cookie and a header are logged by
nobody by default, and neither is copied by pasting a link.

Encrypting or signing the value does not move it out of this list. A stolen
token is useful exactly as stolen, whatever it is made of.

## The two legitimate query reads, and why

Two calls in this repository read a query parameter, and neither reads a secret
into the url that could have gone in a header:

- **`hilosPass`** — the protected-mode admission key
  (`ProtectedModeAdmissionConstants::HILOS_PASS_QUERY_PARAM`, read in
  `WebSocketClient`). This one *is* a secret, and it is the exception the rule is
  built around rather than a loophole in it. While a node is frozen the frontend
  is forbidden outgoing frames, so a verifier has no way to present anything
  after the socket opens — the key has to be on the upgrade request itself, and
  the browser cannot put it in a header there. It is single-use, lives minutes,
  and is stored only as a hash. The decision is HIL-481.
- **`attachmentId`** — the chat attachment being downloaded
  (`ChatAttachmentDownloadHandler`). Not a secret at all: it names a row, and the
  session cookie on the same request is what says whether the caller may have it.
  A url naming a resource is what a url is for.

The difference between the two is worth stating, because it is the thing to
reason about when a third case appears: `attachmentId` is *identification*, and
`hilosPass` is *authorization* that has nowhere else to go for the length of one
upgrade request. Neither is "a token, but ours".

## Where This Applies

- Reading a query parameter anywhere in backend PHP.
- Choosing where a client presents a session token: the WebSocket upgrade, an
  HTTP API request, a download link.
- Designing any new admission or invite flow that hands somebody a link.

## Anti-Patterns

```php
// ❌ The session token as a query parameter — this is what HIL-580 removed from
//    both the HTTP router and the WebSocket handshake:
$token = $queryParams->getString(HilosHttpHeaders::HILOS_SESSION_TOKEN);

// ❌ A header "with a url fallback for convenience" — the fallback is the hole,
//    and it is the path a browser will always take:
$token = HttpHeaderHelper::get($headers, HilosHttpHeaders::HILOS_SESSION_TOKEN)
    ?? $queryParams->getString(HilosHttpHeaders::HILOS_SESSION_TOKEN);

// ❌ An invite or recovery secret read off the link that carried it:
$code = $queryParams->requireString('code');
```

## Preferred Shape

```php
// The header for a client that can set one, then the cookie the daemon issued.
// A value that could not have been minted here is not a token at all.
$presented = HttpHeaderHelper::get($headers, HilosHttpHeaders::HILOS_SESSION_TOKEN)
    ?? HttpHeaderHelper::parseCookies($headers)[$this->sessionCookieName]
    ?? null;

return $presented !== null && SessionToken::isValid($presented) ? $presented : null;
```

Carrying neither is not an error to answer with a mint: a browser that has not
opened a socket yet simply has no session, and the request is anonymous.

`SessionToken` owns the token's form (HIL-556), so a caller checks a presented
value through it rather than by pattern of its own.

## The list, and why it is not a baseline

`SECRET-IN-QUERY` inventories **every** by-key read of `RequestQueryParams`: a
call naming an argument the rule does not list is a violation, wherever it sits.
The list is a constant in
`framework/tests/CodeStyle/Rule/SecretInQueryRule.php`, not a record in
[baseline.txt](../code-style/automated-checks.md) — a baseline entry is debt,
names the leaf that will pay it off, and may only shrink, while these two reads
are a standing choice a new legitimate parameter may join.

The list holds the **text of the argument** as it is written at the call site,
not the value that arrives on the wire: a token walk cannot resolve another
class's constant. Renaming the constant therefore reopens the question, which is
the right outcome — somebody is editing the call anyway.

The rule lists allowed *names* rather than guessing which names look secret. A
"looks like a secret" pattern would have to stay silent on `hilosPass`, putting
the hole exactly where the secret actually is, and it would wave through the next
one called `code`, `sig` or `invite`. The price is that a project adding a
legitimate parameter edits a framework file — the same price `RANDOM-SOURCE`
already charges, and it buys the same thing: a decision written down by the
person who could say why.

## Validation

- The guard reads `getString()`, `requireString()`, `requireStringMatching()` and
  `has()`. It does not read `toArray()`, which hands back the whole map and could
  carry a secret past it. That is the same narrowness the other rules have and
  the same answer applies: it stays a matter for review. No such call exists in
  the scanned roots today.
- The question has been asked once before and answered. P-012 found the
  cookieless session path dead and broken, and weighed giving the session a
  second carrier — a header, a query parameter or a signed one. What came out of
  it was HIL-556: the cookie carries the session, and `SessionToken` owns the
  form. This document is where that answer now lives, so the next proposal meets
  it before the discussion rather than after.
- Run `composer run test:framework:unit`, which carries the guard, its fixtures
  and this document's route.
