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
| the action I just pressed failed, in a dialog | **toast** — the dialog stays open with the entered values, and the notice does not push the form around |
| the action I just pressed failed, on the page itself | the tracked action's own error, next to the button |
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
   Bootstrap surface and the lifetime — an error stays on screen longer than a
   success, because it carries a reason worth reading.
3. Pass `ttlMs` only to override the default lifetime; `ttlMs: 0` keeps a notice
   until the user dismisses it. Use it sparingly — a sticky toast is a modal in
   disguise.
4. Write the message as a whole sentence the user can act on. A failure names
   what failed and why in one line; the full detail belongs in the log, not in
   the corner of the screen.
5. Nothing to mount: `HilosToastHost` is part of `HilosLayout` in all three view
   layers, so any page inside the shell is covered. Mount the host yourself only
   in an app that does not use the framework shell.

## Failures of a tracked action

A dialog's submit does not push its own toast. The tracked-action driver does it:
pass `toast: true` when building it, and it pushes the described failure while
still setting `error` for anything that wants to render it.

```ts
const { loading, busy, run } = useTrackedAction({ toast: true })   // Vue / React
protected readonly edit = createHilosTrackedAction({ toast: true }) // Angular
```

Without the flag the driver behaves as before — `error` is set and nothing is
pushed — which is what a page-level action next to its own button wants.

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

- Unit: the store's queue, lifetimes, and dismissal are covered in
  `core/src/state/toasts.test.ts`; a feature that pushes needs no store test of
  its own.
- E2E: assert inside the stack, not on a per-feature id —
  `page.getByTestId('hilos-toasts').getByText('Password changed.')`. Remember the
  notice expires: assert it before the lifetime elapses, and never assert its
  absence as proof that an action failed.
