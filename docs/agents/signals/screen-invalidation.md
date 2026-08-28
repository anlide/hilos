# Screen Invalidation

Read this before writing a fact from server code — a command handler, an agent
action, a cron sweep, a CLI command — when the question "who is looking at this
right now" has an answer. The fan-out closes most of it; this file is about the
remainder.

## Core Rule

When a fact the server has just written makes the next thing a person does on an
open screen fail or mean something other than what the screen promises, and that
fact is not part of the entity contract of that screen, the server moves the
screen itself. Do not wait for the person's next action to tell them.

## What The Fan-Out Already Does

An entity change reaches the screen on its own. Local code writes DB or RT
through the normal collection/actions layer, the worker turns the queued sync
into a `SourceChange`, records it in `Hilos::$browser`, and
`flushToSignalRouter()` addresses `BrowserPageSignalData` to the local accept
keys whose subscribed page declared that source as part of its contract. Every
other worker of the node applies the same sync and records the same fact in its
own browser context. See
[browser-source-fanout.md](../architecture/browser-source-fanout.md), "Source
Flow" and "Delivery".

So the first question is not "what do I send" but "is the screen already
subscribed to what I changed". If it is, send nothing: an imperative fan-out on
top of a declared source is the anti-pattern that file already names.

## When The Rule Applies

Both conditions must hold. One alone is not enough.

1. **The next action on the open screen is now wrong.** Four shapes, and the
   fourth is the mirror of the first three:
   - a code that will no longer be accepted;
   - a button that will do nothing;
   - a form that will save to the wrong place;
   - a refusal that no longer holds — the screen shows a denial the server would
     not issue again.
2. **The fact that caused it is not in the entity contract of that screen.** The
   fan-out delivers what a page declared as its sources; a fact outside that set
   never reaches it, however material it is. Without this condition the rule
   would call a defect what the architecture already closes.

## How The Screen Is Moved

Move it with the frame that surface already speaks. Do not invent a second
vocabulary for the same surface.

- A screen living on a **page subscription** is moved by the frame that answers a
  subscription normally: a full `page_response` when the verdict allows, and the
  `subscription_page_error` that same verdict would have produced when it denies.
  One path, one shape on the wire — see
  [page-access-control.md](../architecture/page-access-control.md),
  "Re-deciding an OPEN page when rights change".
- A surface living on a **flow step** is moved by its own flow's signal —
  `AuthConvergeSignalData` is the worked example: the push half of a step change
  nobody in that browser asked for.

**The move ends in a frame the surface knows how to take.** Announcing that the
rights changed and then not answering the page is half a move: the frontend
clears the error it is showing and starts waiting for the answer, so a person
told "something changed" and never answered waits forever. That is what
`bindAccessReaction` does when the admin marker returns while a 403 is on
screen — it drops the error and calls `awaitPageAnswer()`. The announcement and
the answer are one operation, not two.

## Two Worked Examples

Both already exist in the tree, and both were written as a property of one
feature rather than as a general mechanism. The rule names them as instances; it
does not restate how they work.

**Re-deciding an open page when rights change.** The sweep starts where the tabs
are told who their person now is: `PageAccessReassessment::forUser()` queues one
`page_access_reassess_user` announcement and returns; each worker of the node
then runs `PageAccessReassessment::sweepThisWorker()` over its own live page
subscriptions and queues one `page_access_reassess` frame per page that person
has open there. Its one call site is the project's, and a grant reaches it the way
every other identity change does: `AbstractSessionsLibraryAgent` writes the flag
through the project's `applyAdminGrant` seam and then restates each live session of
that person (HIL-729).
A session losing its person is the same obligation with the other criterion:
`forConnections()` / `sweepThisWorkerConnections()` name the accept keys instead,
because the identity the first pair matches on is precisely what signing out
removes. The one seam every way of losing it passes through is the
`hilos_session_state` frame the sessions library ends it in, and the project
handler of that frame is where both criteria are chosen between. Described in
[page-access-control.md](../architecture/page-access-control.md).

**Converge of a registration.** A session parked on the code step of an
identifier somebody else is confirming is not the session that submitted, so
nothing in its own request will ever tell it the outcome.
`AbstractSessionsLibraryAgent::convergeRegistration()` says it to the waiters,
`rollBackRegistrationWaiters()` returns them to the identifier field when the
reservation expired instead of failing the code they were about to type, and
`convergeRecovery()` does the same for recovery. The carrier is
`AuthConvergeSignalData`.

## Anti-Patterns

- **A "something changed" notification as a third shape of frame.** A payload
  that means "re-read everything" forces the frontend to grow code for a frame
  no other path sends. Send the frame the surface already answers to.
- **Half a move.** Announcing the change to the surface without the answer it now
  waits for. The person is left in a permanent wait, which is worse than the
  stale screen the announcement was meant to fix.
- **Waiting for the person's next action** to report the failure or the success.
  The action is the thing the rule exists to keep from failing.

## What This Rule Does Not Declare

Deliberately out of scope, decided 2026-08-23:

- **No subsystem-wide invariant.** There is no entry for this in the
  "Key rules (always apply)" list of `agents.md`, and the rule is not phrased to
  make every write without a signal retroactively a defect.
- **No enforcement mechanism.** Nothing here is machine-checked, and no guard is
  proposed for it.
- **No sweep of the places that are silent today.** Existing silent paths are
  fixed by their own leaves, not by this file.
- **No cross-node reach.** A re-decision reaches every open page of that person
  **on the node**: the writing worker announces, the master fans the announcement
  to every worker, and each worker sweeps its own mirror. A tab on another node
  is a separate subject.

## Related

- [browser-source-fanout.md](../architecture/browser-source-fanout.md) — what
  arrives on its own, and why most writes need nothing.
- [page-access-control.md](../architecture/page-access-control.md) — the
  re-decision of an open page, in full.
- [subscriptions.md](subscriptions.md) — one subscription answers everything the
  page renders.
- [core-and-connection.md](../frontend/core-and-connection.md) — the client's
  half of the keystone.
