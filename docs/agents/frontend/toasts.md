# Toasts

Read this before showing the user any outcome that is not attached to the thing
they are looking at — a run that finished after its action was acked, a reply
that came back late, a background job reporting how it went — and before
deciding that the corner stack is the right surface at all.

A **toast** is a short notice that something happened. It demands no reply,
blocks no work, and expires on its own — except an error, which stays until the
user closes it. It is presentation only for the toast of **my own action**: the
backend reports domain outcomes and failures, and the frontend decides which of
them deserve a toast ([wire-protocol.md](wire-protocol.md)). A toast addressed to
the **session** is the other way round — the backend authors it and the backend
stores it, because the tabs of one browser have to agree about it (see *Tabs of
one session* below). Nothing shown in a toast may be the only copy of that
information — a toast that expires is gone.

Rules that the code does not implement yet end with a literal marker
`(not in the code yet — HIL-<n>)` naming the leaf that will land them. The leaf
that lands the behavior clears its markers, in the same commit — see
[rule-authoring.md](../rule-authoring.md).

## Who a toast is addressed to

A toast lives only while someone is looking at it, so it has exactly **two
addressees, and no third** — both picked so that the onlooker is guaranteed to
exist:

- **The connection that acted.** The user pressed the button and is standing
  right there. On the backend this is `AbstractAgent::sendToUser()` — despite
  the name it reaches **one connection** (signal type
  `SignalTypeConstants::WS_USER`, addressed by `targetAcceptKey`).
- **The session the server is answering.** Every tab of one browser:
  `AbstractAgent::sendToSession()` (signal type
  `SignalTypeConstants::WS_SESSION`, addressed by `targetSessionTokenHash`).

**There is no "user" level.** A message addressed to the person in general —
not to their current window — is never shown as a toast: the person may own
three devices with two of them switched off, and an expiring card will not
wait for them. Anything addressed to the user belongs on a surface that does
wait — a banner, or a durable record in the notification center (the
`hilos_notifications:<userId>` group,
`core/src/notifications/notificationCenter.ts`).

## Which surface: toast, banner, or record

Pick by who the addressee is and how long the fact matters: an instant outcome
of the current window is a **toast**; a state that lasts is a **banner**; what
may be needed tomorrow — or is addressed to the person in general — is a
**record** (the notification center, a history row, a status field).

| The fact | Surface |
|---|---|
| an action succeeded, and the result is not visible on screen | **toast** |
| a background event: an export finished, a notification arrived | **toast** |
| an action failed, and someone has to learn it here and now | **toast** |
| the result is visible by itself: the row disappeared, the switch flipped | nothing — the screen already said it |
| this field / this form is wrong | inline, next to the field — never a toast |
| a decision is needed to proceed | a modal |
| a state that lasts, until it ends | a banner, not an event notice |
| nobody asked for it, and no trace would remain | a record — not a toast |

A banner is only named here — its shape and mechanics are HIL-736's. The last
row is the one most often got wrong: an unattended outcome must not interrupt
whoever happens to be connected; it belongs in the record.

## My own action, and a background one

The two addressees look different on screen:

- **My own action** (the connection that acted) carries no source signature —
  the user knows what they just pressed — and the card is not obliged to lead
  anywhere: they are already at the place the outcome describes.
- **A background one** (the session) **names its sender** and is **obliged to
  lead to the record** it announces. A background toast with nowhere to lead
  is a subsystem that failed to create a record — fix the subsystem, not the
  toast. It is also raised somewhere else entirely: not by `push()`, which has
  no session form at all, but by the backend — an agent sends
  `hilos_session_toast_raise` to the sessions library, naming the session by the
  hash of its cookie token
  (`AbstractAgent::resolveInitiatorSessionTokenHash()`), the sentence, the
  sender and the path. `BackupAgent::announceCreatedBackup()` is the worked
  example.

Leading is the whole card as one click target (a stretched link, like a list
row) — never a button inside the card.

## What a toast never has

- **Buttons.** An action inside an expiring card runs away from under the
  hand: the user must notice, read, decide and land the cursor before the card
  is gone. The card may *lead* — one click on the whole card — but it can *do*
  nothing.
- **History.** A toast that was shown and flew away is lost, and that is
  intended. What may be needed later must have its own record, and the toast
  only points at it; a toast history would grow into a dump where a proper
  journal belongs. What was never DELIVERED is not history but a debt: a notice
  that did not fit in the corner is kept until it has been shown once (see
  "Lifetime, pause, and what overflows") — and then it goes the same way.
- **Connection messages.** Losing the connection is a state, not an event — it
  has the indicator in the shell's header. No sound and no vibration either.
- **A showing before the first frame.** A notice that arrives before the app
  has rendered its first frame waits for that frame; it does not pop up over
  the splash screen.

## How to show one

Push into the shared store; the shell already renders it — `HilosToastHost` is
part of `HilosLayout` in all three view layers, so any page inside the shell
is covered (mount the host yourself only in an app that does not use the
framework shell):

```ts
import { hilosToasts } from '@hilos/core'

hilosToasts.push('Password changed.', { severity: 'success' })
hilosToasts.push(reason, { severity: 'error' })
```

There are **four severities**: `error` (something failed), `success`
(something the user asked for completed), `warning` (it worked, with a
caveat), and `info` (neither). Write the
message as one whole sentence the user can act on — and never the engine's own
words ([wire-protocol.md](wire-protocol.md), "A failure reason is a domain
sentence").

A view does not push its own toast for a submit. The **tracked-action driver**
does it — by default, with no flag (`useTrackedAction()` in Vue and React,
`createHilosTrackedAction()` in Angular):

- **Success** text is backend-authored: the `action_success` reply carries an
  optional `message` (set from `onAction()` via
  `AbstractPage::setActionSuccessMessage()`), and the domain sentence lives on
  the backend because Hilos i18n does. A reply that carries no `message` shows
  no success toast at all — the driver has no sentence of its own, and silence
  is what the backend asked for.
  So a new tracked action decides one of two things, and never a third: it
  names its outcome in a sentence, or it stays silent because the screen
  answers for itself (the row disappeared, the switch flipped). There is no
  guard on this: silence is a legitimate outcome, and no check can tell it
  apart from a forgotten one.
- **A failure of my own action is shown where the person acted.** The modal in
  which the action was refused shows the refusal itself, with `HilosActionError`
  in the place reserved for it — and on an admin surface with the type badge and
  detail panel beside it (HIL-779). The corner still carries it as well: the
  toast is what reaches a person who has already looked away.
  The place is reserved always, and by the component itself: with nothing to
  say it draws an invisible twin of the plate, which is exactly as tall as the
  plate would be. So the form does not move when a refusal arrives — and it
  does not move for a long one either, because the plate is one line at any
  length: the sentence truncates, and the whole of it is read in the detail
  panel behind a button that stands on every refusal (HIL-887). A surface that
  answers the same action somewhere else — a confirmation of its own — passes
  `suppressed` instead of dropping the component: the room has to stay, or
  opening the confirmation moves the page under its own backdrop.
- **Failure text is backend-authored too.** The driver prints what the reply
  carried; its own phrasing is left for the outcomes no backend sentence exists
  for — the timeout, the dropped connection, the unreadable reply.

Opting out is `toast: false`, with the reason written at the call site. It
covers the refusal, which is the only outcome the option still has to suppress —
a success it has nothing to say about, because the backend already decides that
one by authoring a sentence or staying silent. The two reasons that qualify:

- **field validation** — the message belongs against the field it describes;
- **sign-in and verification forms** — "wrong password" answers the value the
  user just typed, on a form they are already looking at.

Drawing the refusal in the dialog is **not** one of them: `HilosActionError`
sits beside the toast, it does not replace it.

Prefer pushing from the **core headless** rather than from each view — one
call covers Vue, React and Angular at once:

```ts
// core/src/admin/backup/hilosBackups.ts
context.connection.on('actionError', (signal) => {
  if (signal.requestId === undefined && BACKUP_ACTIONS.has(signal.action)) {
    hilosToasts.push(signal.reason, { severity: 'error' })
  }
})
```

A project may render its own stack by creating an independent store
(`createHilosToastStore()`) and passing it to the host — the shared
`hilosToasts` singleton is a default, not a requirement.

## Lifetime, pause, and what overflows

- **Twenty seconds; an error — until dismissed.** Twenty seconds is time to
  read, not time to notice. An error does not expire at all: the user closes
  it, and that is the only thing a toast ever requires of a person.
- **Time belongs to the store.** The caller does not set a lifetime, and the
  push contract carries no `ttlMs` option — one behavior instead of a flag.
- **The countdown measures reading time, not wall time.** It freezes under
  three independent, counted holds: the cursor over the stack, keyboard focus
  inside it, and the tab not being visible.
  Releasing one hold while another is still held resumes nothing; on release
  the countdown continues from what is left. Walk away to another tab and
  everything is still there when you come back.
  The cursor hold is taken only by the engine's own `mouseover` — which the
  engine also sends for a card arriving or sliding under a still pointer — and
  is given back by `mouseleave` AND whenever a card that was on screen leaves
  the stack, because a removed node takes its `mouseleave` with it. The focus
  hold is given back by `focusout` and whenever, after a redraw, focus is no
  longer inside the stack. Neither is ever re-read from `:hover` after a
  render: that read is stale by construction and froze the tab for good
  (HIL-916). One gap is accepted: a pointer resting on a card that does not
  move while another card leaves by the keyboard or the server — the countdown
  runs until the mouse moves.
- **The cap is a third of the window height, not a card count.** Five short
  notices and two long ones occupy different space, and a phone and a monitor
  differ more still — the limit is measured in the unit the problem actually
  has.
- **When space runs out, nothing is destroyed — an error waits, the rest is
  missed.** Nothing old is silently evicted: the new card waits. A queued error
  takes the next slot freed by a dismissal or an expiry. Success, info and
  warning that do not fit are **missed** — kept, oldest first, rather than
  counted and forgotten, because a notice nobody saw is owed, not gone.
  - **Freed room gives them back by itself, errors first.** Only when no error
    is waiting does a freed slot take the oldest missed notice, one at a time:
    the host draws and measures it, and the next follows only if the stack is
    still within the cap. One that turns out not to fit goes back to the head
    of the list. Left alone, the corner works through a backlog on its own,
    each card expiring and pulling in the next.
  - **The service line** reads "N more waiting · M missed" and does not count
    toward the height cap; it goes away once nothing is waiting and nothing is
    owed. The "M missed" half is a control (`hilos-toast-missed`, named
    "M missed, show one"): a press puts the oldest missed notice on screen at
    once, **over the cap** — the cap bounds what arrives unasked, and this was
    asked for. "N more waiting" stays plain text: queued errors arrive by
    themselves and need no door. When the press takes the last missed notice,
    focus moves to that card's close button.
  - **A returned notice gets a full, fresh countdown** — it never had one, and
    the countdown measures reading time.
  - **There is no staleness horizon.** A missed notice is worth showing until
    it is shown: a clock would forget what a person never saw while they were
    in another tab, which is exactly the loss this rule exists against.
  - **The missed list is per window**, like the cap it comes from, and never
    reaches the server. A session's card missed here is not answered about —
    no countdown ran here — and when a neighbouring tab has shown it and the
    server takes it off, the next frame takes it out of this list too.

  The store keeps the queue and the missed notices and publishes their two
  numbers, and every host draws the line from that one signal.
- **A repeat does not multiply cards.** A push whose text *and* severity
  exactly match a visible card bumps a ×N counter on that card and restarts
  its countdown; the merge itself is in the store, the ×N badge on the card in
  the hosts. A twin that is waiting or missed takes the repeat as well and
  stays where it is — a repeat brings no room. Full-match dedup is deliberate: if the text carries an object's
  name, the texts differ anyway.
  Remember the merge treats a symptom — twenty identical failures are almost
  always one dead server that twenty actions crashed against; ×20 makes it
  bearable, the fix happens where the errors are born.
- **Text is clipped at three lines.** The details live in the record, not in
  the corner.
- **Tabs of one session.** Each tab runs its own countdown — the cursor
  hovers in one tab and not in another, and that is two tabs, not a desync.
  What IS shared is the card's existence, and it is shared through the server:
  the stack of a session is an RT row the sessions library owns
  (`hilosSessionToastStacks`), it reaches every tab as one frame carrying the
  whole list (`hilos_session_toasts`), and a tab answers about it rather than
  deciding — `hilos_toast_dismiss`, `hilos_toast_expired`,
  `hilos_toast_reading`. Closing is the person's answer, and the person is one
  per session: dismissed in one tab — gone in all, and a countdown that burned
  out anywhere ends the toast everywhere. **Reading outranks another tab's
  extinguishing:** while a cursor rests on the stack or the keyboard focus is
  inside it, a neighbour's finished countdown waits, and it fires the moment the
  last reader lets go. A hidden tab is not reading — it freezes its own
  countdown, but a background tab of the admin panel that held the stack would
  make every toast immortal in the window actually in use.
  Two consequences worth knowing: **closing is not optimistic** — the card
  leaves on the frame that follows, so on a dead connection it stands under the
  finger — and **a tab opened later** is shown what the session is still owed,
  with a fresh countdown of its own, because the card has only now come into
  view.
  **A sign-out empties the stack.** When the session is left with nobody
  behind it, whatever road led there — the shell sign-out, expiry, an account
  merge, a password reset from another device — every tab empties its corner
  (cards, waiting errors, missed notices, both numbers of the service line),
  and the server drops the session's stack and says so with the empty list:
  a toast answers somebody, and that somebody has left. The trigger is the
  person being gone (`clearToastsOnSignOut`, bound by `bootHilos`), not the
  press of a button, so a refused sign-out keeps its refusal. A sign-in
  clears nothing — the guest's notices are read by the person who just signed
  in, and the rotated session is owed nothing — and neither does an
  impersonation, which keeps the same administrator at the keyboard.

## Where the stack sits

Bottom right by default; on a narrow screen the corner is always the top
right — the bottom of a phone is occupied by the form's buttons, and the
keyboard rises from there. A project may move the corner **once, at build
time**, never per call: different corners in different sections of one product
is a reliable way to make the notices stop being noticed. The stack sits above
a modal and is not covered by its backdrop.

## Accessibility

The live region is declared **in advance, on the stack itself** —
`role="status" aria-live="polite"` — not on the card that just appeared: a
role attached to a freshly inserted element leaves part of the screen readers
silent. An error is the only notice allowed to interrupt:
`role="alert" aria-live="assertive"`.

The lifetime rules above are how the timing criterion (2.2.1 Timing
Adjustable) is met: the countdown freezes while the stack is being read, and
an error waits indefinitely. See [accessibility.md](accessibility.md).

## Anti-Patterns

- An `alert alert-success` that a comment calls a "toast". If it belongs in
  the corner stack, put it there; if it belongs in the page, do not call it a
  toast.
- A toast carrying information with no durable home ("backup 3 of 7 failed"
  and nothing in the list says so).
- A toast for a validation error the user can fix in the form in front of
  them.
- A toast for a result the screen already shows — the row disappeared, and a
  card in the corner says it again.
- Broadcasting to every connection, or addressing "the user": a toast has two
  addressees — the connection and the session — and no third.
- A button, an action link row, or any second control inside the card — the
  close button is the only control a toast has.
- Reaching for Bootstrap's JS `Toast`: the SDK ships Bootstrap's CSS only, and
  the store owns visibility (the same reason `HilosModal` renders
  `.modal.show` itself).

## Validation

- Unit owns the clock, the pause, the cap, the merge and the return of missed
  notices: `core/test/state/toasts.test.ts` covers the store; the host's pause
  events and live-region wiring live in `react/test/HilosToastHost.test.tsx`,
  and the service line's control is covered in all three hosts. The sign-out
  clear is covered in `core/test/session/sessionToasts.test.ts`, the holds'
  return in the three hosts' units. A feature that pushes needs no store test
  of its own.
- **Not e2e, on purpose.** The lifetime, the pause and the cap are timing, and
  asserting them through a browser would mean a spec that sits still for 20
  seconds to prove a toast is still there — slow, and flaky the moment the box
  is loaded.
- E2E asserts only that a notice appears at all, and inside the stack —
  `page.getByTestId('hilos-toasts').getByText('Password changed.')`. Remember
  the notice expires: assert it before the lifetime elapses, and never assert
  its absence as proof that an action failed.
