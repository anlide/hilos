---
name: hilos-page-subscribe
description: Work with Hilos page subscription params, PageRouteParams accessors, AbstractPageSubscribeParamsDTO subclasses, onSubscribe/onUpdateSubscription signatures, template method dispatch in abstract pages, and subscription error signals. Use when adding or changing page route params or subscribe handlers.
---

# Hilos Page Subscribe Params

Use this skill when adding a new page that accepts route params, changing an
existing page's route param contract, reviewing `onSubscribe()` /
`onUpdateSubscription()` implementations, or tracing `subscription_page_error`
signals back to their source.

## Read First

- Subscribe handlers, `PageRouteParams` accessors, per-page DTO template:
  `docs/agents/code-style/page-action-handlers.md` (section
  "Subscribe handlers and route params")
- Subscription lifecycle (`PAGE_SUBSCRIBE` / `PAGE_UPDATE_SUBSCRIPTION`):
  `docs/agents/signals/subscriptions.md`
- Subscription error signals on the wire:
  `docs/agents/frontend-sdk/backend-contract.md` (section "Subscription errors")
- `PageSubscriptionException` taxonomy and `@throws` style: use
  `$hilos-exception`

## Workflow

1. Decide whether the page has real route params. If it has none, keep
   `onSubscribe(string $acceptKey, PageRouteParams $params): void` empty and
   skip the DTO.
2. For pages with params, add the key to `Hilos\Constants\HilosPageRouteParams`
   (or a page-level constant for demo-only pages) and mirror it in
   `framework/frontend/src/constants/hilosPageRouteParams.ts` if the frontend
   also uses the key.
3. Create a `SomePageSubscribeParams` DTO extending
   `AbstractPageSubscribeParamsDTO` with readonly promoted properties and a
   `public static function fromPageRouteParams(PageRouteParams $params): static`
   factory.
4. Parse values via the narrowest accessor: `requireString`, `requireInt`,
   `requirePositiveInt`, `requireEnum`, or the matching `get*` variants when
   the param is optional. `require*` is missing-safe (throws
   `MissingPageRouteParamException`); any accessor throws
   `InvalidPageRouteParamException` on malformed non-empty values.
5. In the abstract page class, make `onSubscribe()` `final` and dispatch to a
   `protected abstract function onSomePageSubscribe(string $acceptKey,
   SomePageSubscribeParams $params): void`. Do the same for
   `onUpdateSubscription()` when the page uses it.
6. Keep domain checks (entity exists, permissions, lookup by id) inside the
   page handler, not in the DTO. Throw `PageResourceNotFoundException` or
   another `PageSubscriptionException` subclass after the DTO has validated
   the raw route shape.
7. `PageRouteParams` never performs DB lookups. Do not import collections,
   actions, or `Hilos::$db` inside `fromPageRouteParams()`.
8. When the concrete page has no subclasses and no shared subscribe logic,
   parse the DTO directly in its own `onSubscribe()` without a template method;
   reserve the `final`/`abstract` split for abstract pages with more than one
   concrete subclass.
9. Run `composer test:framework:unit` after changing `PageRouteParams`, its
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
- Once an abstract page introduces a typed subscribe DTO, keep its
  `onSubscribe()` / `onUpdateSubscription()` `final` so subclasses cannot
  bypass the parsed DTO.
