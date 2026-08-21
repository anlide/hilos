---
name: hilos-page-subscribe
description: Work with Hilos page subscription params, page registration and routing, PageRouteParams accessors, AbstractPageSubscribeParamsDTO subclasses, onSubscribe/onUpdateSubscription signatures, template method dispatch in abstract pages, and subscription error signals. Use when adding or changing page route params or subscribe handlers, and when a page needs one more piece of data to render and you are deciding where that data comes from.
---

# Hilos Page Subscribe Params

Use this skill when adding a new page that accepts route params, changing an
existing page's route param contract, reviewing `onSubscribe()` /
`onUpdateSubscription()` implementations, or tracing `subscription_page_error`
signals back to their source.

## Read First

- Page registration and subscription routing:
  `docs/agents/app-topology.md`
- Subscribe handlers, `PageRouteParams` accessors, per-page DTO template:
  `docs/agents/code-style/page-action-handlers.md` (section
  "Subscribe handlers and route params")
- Subscription lifecycle (`PAGE_SUBSCRIBE` / `PAGE_UPDATE_SUBSCRIPTION`), and
  the rule that one page subscription answers with everything the page renders
  — read the section of that name before adding anything a page waits for on
  top of its subscription reply: `docs/agents/signals/subscriptions.md`
- `PageSubscriptionException` taxonomy and `@throws` style: use
  `$hilos-exception`

## Workflow

1. Decide whether the page has real route params. If it has none, omit
   `onSubscribe()` and rely on `AbstractPage::onSubscribe()` unless the page
   needs specialized subscribe behavior; skip the DTO in that case.
2. When adding a page or changing its subscription owner, update project
   topology through `Hilos::PAGES` and page `SUBSCRIPTION_AGENT_TYPE` as
   described in `docs/agents/app-topology.md`; `SignalRouter` reads these
   owners through the project facade hook.
3. For pages with params, add the key to `Hilos\Constants\HilosPageRouteParams`
   (or a page-level constant for demo-only pages).
4. Create a `SomePageSubscribeParams` DTO extending
   `AbstractPageSubscribeParamsDTO` with readonly promoted properties and a
   `public static function fromPageRouteParams(PageRouteParams $params): static`
   factory.
5. Parse values via the narrowest accessor: `requireString`, `requireInt`,
   `requirePositiveInt`, `requireEnum`, or the matching `get*` variants when
   the param is optional. `require*` is missing-safe (throws
   `MissingPageRouteParamException`); any accessor throws
   `InvalidPageRouteParamException` on malformed non-empty values.
6. In the abstract page class, make `onSubscribe()` `final` and dispatch to a
   `protected abstract function onSomePageSubscribe(string $acceptKey,
   SomePageSubscribeParams $params): void`. Do the same for
   `onUpdateSubscription()` when the page uses it.
7. Keep domain checks (entity exists, permissions, lookup by id) inside the
   page handler, not in the DTO. Throw `PageResourceNotFoundException` or
   another `PageSubscriptionException` subclass after the DTO has validated
   the raw route shape.
8. `PageRouteParams` never performs DB lookups. Do not import collections,
   actions, or `Hilos::$db` inside `fromPageRouteParams()`.
9. When the concrete page has no subclasses and no shared subscribe logic,
   parse the DTO directly in its own `onSubscribe()` and call
   `parent::onSubscribe()` when browser snapshots are needed; reserve the
   `final`/`abstract` split for abstract pages with more than one concrete
   subclass.
10. Prefer `parent::onSubscribe()` over manual `sendToUser()` with empty
    `SignalData` or `BrowserPageSignalData` for subscription acks.
11. Run `composer test:framework:unit` after changing `PageRouteParams`, its
   DTOs, or any abstract page's subscribe contract.

## Hard Rules

- Never run `git commit` or `git push`.
- Do not index `$params` directly (`$params['id']`) when the page has real
  route params — parse into a DTO or use a typed accessor.
- Do not perform DB lookups or permission checks inside
  `fromPageRouteParams()`.
- Do not catch `MissingPageRouteParamException` or
  `InvalidPageRouteParamException` inside page code; let the router convert
  them into a `subscription_page_error` signal.
- Do not keep page subscription ownership in project SignalRouter code for
  registered pages; keep it on page `SUBSCRIPTION_AGENT_TYPE` and let
  `SignalRouter` read `Hilos::getPageRoutes()`.
- Once an abstract page introduces a typed subscribe DTO, keep its
  `onSubscribe()` / `onUpdateSubscription()` `final` so subclasses cannot
  bypass the parsed DTO.
- Do not answer a page's first render with a second round trip: no extra
  client→server action, no companion signal the client waits for alongside
  `page_response`, no standalone catalog request. Read the foreign source in
  that page's `buildPagePayload()` / `onSubscribe()` and send one frame.
