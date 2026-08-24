# Toasts

Read this before showing the user any outcome that is not attached to the thing
they are looking at: a run that finished after its action was acked, a reply that
came back late, a background job reporting how it went.

## Core Rule

A **toast** is a transient, self-expiring notice in the shell's corner stack. It
is presentation only. The backend never asks for one: it reports domain outcomes
and failures, and the frontend decides which of them deserve a toast
([wire-protocol.md](wire-protocol.md)). Nothing shown in a toast may be the only
copy of that information — a toast that expires is gone.

Pick the surface by where the user is looking and how long the fact matters:

| The fact | Surface |
|---|---|
| this field / this form is wrong | inline, next to the field — never a toast |
| the action I just pressed failed | **toast** — this is the default, in a dialog and on a page alike |
| something I started earlier finished or failed | **toast** |
| a late reply reconciling after a timeout | **toast** |
| an outcome the user may need tomorrow | the feature's own record (a history row, a status field) — a toast may accompany it, never replace it |
| nobody asked for it (a schedule, a cron, another user's action) | no toast — it belongs in the record |

That last row is the one most often got wrong: an unattended failure must not
interrupt whoever happens to be connected. Address the notice to the connection
that asked for the work, or to nobody.

## Workflow

1. Push into the shared store; the shell already renders it.

   ```ts
   import { hilosToasts } from '@hilos/core'

   hilosToasts.push('Password changed.', { severity: 'success' })
   hilosToasts.push(reason, { severity: 'error' })
   ```

2. Choose the severity honestly: `error` (something failed), `success` (something
   the user asked for completed), `info` (neither). The severity drives the
   Bootstrap surface and the lifetime — **20 seconds** by default, **40 seconds**
   for an error. An error gets double because it carries a reason the user has to
   read and understand, not merely notice.
3. Pass `ttlMs` only to override the default lifetime, and only with the reason
   written at the call site — a lifetime with no reason beside it is the next
   person's mystery. `ttlMs: 0` keeps a notice until the user dismisses it. Use it
   sparingly — a sticky toast is a modal in disguise, and it is evicted by the
   visible cap like any other.
4. Write the message as a whole sentence the user can act on. A failure names
   what failed and why in one line; the full detail belongs in the log, not in
   the corner of the screen — and never the engine's own words
   ([wire-protocol.md](wire-protocol.md), "A failure reason is a domain
   sentence").
5. Nothing to mount: `HilosToastHost` is part of `HilosLayout` in all three view
   layers, so any page inside the shell is covered. Mount the host yourself only
   in an app that does not use the framework shell.

**Reading time is protected, and the corner is capped.** The countdown of the
whole stack freezes while it is under the cursor or holds keyboard focus, and on
leaving it continues from what is left, not from the full lifetime — the two are
independent holds, so leaving with the cursor while focus is still inside does
not restart anything. Twenty seconds is enough to read a notice only if the
notice is still there, which is why the stack shows at most **4 at once**: a
fifth push evicts the oldest. While the stack is held, eviction waits — a pause
promises that nothing disappears while the user is reading, so the excess leaves
in one move on release. None of this is a per-call option: the host reports the
events, the store owns the policy, and a caller has nothing to wire.

## Failures of a tracked action

A view does not push its own toast for a submit. The tracked-action driver does
it — by default, with no flag — and still sets `error` for anything that wants to
render it:

```ts
const { loading, busy, run } = useTrackedAction()   // Vue / React
protected readonly edit = createHilosTrackedAction() // Angular
```

**Do not render `error` as an inline alert alongside this.** The toast is the
surface; a banner as well is the same failure said twice, and it shoves the form
down as it appears.

Opting out is `toast: false`, and it needs a reason at the call site. The two
that qualify:

- **field validation** — the message belongs against the field it describes;
- **sign-in and verification forms** — "wrong password" answers the value the
  user just typed, on a form they are already looking at.

## Success of a tracked action

A submit that commits toasts success the same way — by default, with no flag.
The same `toast: false` that opts out of the failure toast opts out of the
success one too (the two outcomes share one switch), so the field-validation and
sign-in forms above stay quiet on both.

The text is **backend-authored**: the `action_success` reply carries an optional
`message` (PHP `PageActionSuccessSignalData`, set from `onAction()` via
`AbstractPage::setActionSuccessMessage()`), which the driver shows. The domain
sentence lives on the backend because Hilos i18n does — the backend authors and
localizes it, the frontend only decides to toast it. Until a handler supplies
one, the driver falls back to a generic client string; that fallback is
transitional, not a place to phrase the outcome. This is the success-side
symmetry of the wire-protocol rule that a failure reason is a backend-authored
domain sentence, never the engine's own words.

## Preferred Shape

Prefer pushing from the **core headless** rather than from each view — one call
covers Vue, React and Angular at once:

```ts
// core/src/admin/backup/hilosBackups.ts
context.connection.on('actionError', (signal) => {
  if (signal.requestId === undefined && BACKUP_ACTIONS.has(signal.action)) {
    hilosToasts.push(signal.reason, { severity: 'error' })
  }
})
```

## Anti-Patterns

- An `alert alert-success` that a comment calls a "toast". If it belongs in the
  corner stack, put it there; if it belongs in the page, do not call it a toast.
- A toast carrying information with no durable home ("backup 3 of 7 failed" and
  nothing in the list says so).
- A toast for a validation error the user can fix in the form in front of them.
- Broadcasting an unattended failure to every connected client.
- Reaching for Bootstrap's JS `Toast`: the SDK ships Bootstrap's CSS only, and
  the store owns visibility (the same reason `HilosModal` renders `.modal.show`
  itself).

## Exceptions

A project may render its own stack by creating an independent store
(`createHilosToastStore()`) and passing it to the host — the shared
`hilosToasts` singleton is the default, not a requirement.

## Validation

- Unit: the store's queue, lifetimes, dismissal, the paused countdown and the
  visible cap are covered in `core/src/state/toasts.test.ts`; the host's pause
  events and live-region wiring, in `react/test/HilosToastHost.test.tsx`. A
  feature that pushes needs no store test of its own.
- **Not e2e, on purpose.** The lifetime, the pause and the cap are timing, and
  asserting them through a browser would mean a spec that sits still for 20
  seconds to prove a toast is still there — slow, and flaky the moment the box is
  loaded. The unit test owns them; e2e asserts that a notice appears at all.
- E2E: assert inside the stack, not on a per-feature id —
  `page.getByTestId('hilos-toasts').getByText('Password changed.')`. Remember the
  notice expires: assert it before the lifetime elapses, and never assert its
  absence as proof that an action failed.
