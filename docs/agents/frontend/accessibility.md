# Accessibility

The Hilos frontend targets **WCAG 2.1 level AA**, in full, from v1 — not a later
bolt-on. This document is the normative checklist: the standard, how each
requirement is met, the component and page patterns to follow, and the parts the
SDK gets for free from Bootstrap. Read it before adding or reviewing any view,
page, or SDK component. Styling lives in [styling-rules.md](styling-rules.md);
this is its accessibility companion, and the four pillars in
[rules-and-violations.md](rules-and-violations.md) §G point here.

## The bet: Bootstrap for free, Hilos for structure

Accessibility comes from two layers, and knowing which owns what keeps the work
small and stops it from regressing:

- **Bootstrap 5.3 (the SDK's styling bet) ships the visual a11y layer.** Stock
  components carry `:focus-visible` focus rings, a nuanced
  `prefers-reduced-motion` story (it *slows* spinners rather than freezing them),
  and an AA-tuned default theme (the `-subtle`/`-emphasis` pairs and `text-bg-*`
  use computed contrast). Because every view is built from stock Bootstrap
  classes ([styling-rules.md](styling-rules.md)), these come for free.
- **Hilos owns structure and semantics.** Landmarks, headings, ARIA roles and
  names, the use-of-color text alternatives, focus management, and the live
  regions are the application's job — they are what the rest of this document
  specifies.

**Do not hand-author CSS to "improve" the Bootstrap layer.** A blanket
`prefers-reduced-motion` reset is the classic trap: it overrides Bootstrap's
per-component handling and freezes loading spinners (a frozen spinner reads as
broken), and it violates the no-hand-CSS rule. The Sass layer is the only home
for a documented exception, and none is needed for AA today.

## The four pillars

The baseline, mandated since the first spec:

- **Focus trap and focus return in modals.** Every edit surface is a modal
  (`HilosModal`); it traps focus while open, restores focus to the trigger on
  close, closes on Esc, and exposes `role="dialog"` + `aria-modal` + an
  accessible name — from its title when it draws one, and otherwise from the
  heading that names it elsewhere (see below). See
  [conflict-resolution.md](conflict-resolution.md).
- **Full keyboard operability.** Every interactive element is a real `<button>`,
  `<a>`, or form control (stock Bootstrap), so it is focusable and operable from
  the keyboard with no extra work. Never wire a click onto a non-interactive
  element (a `<span>`/`<div>`); use the real control.
- **ARIA roles and names.** Every control has an accessible name; icon-only
  controls carry an `aria-label` and mark the icon `aria-hidden="true"`; status
  surfaces carry the right role. Decorative glyphs and emoji are `aria-hidden`.
- **Visible focus and adequate contrast.** Delivered by Bootstrap's
  `:focus-visible` rings and AA-tuned theme — kept intact by building from stock
  classes and never suppressing outlines.

## A name that lives in another component

A dialog or a region whose name is written by a **different** component points at
that component with `aria-labelledby` and a stable id. It does not keep a copy of
the text, and it does not have the other side publish the string to it: two
values that must say the same thing drift apart on exactly the step nobody
remembered to update, and the drift is invisible to whoever reads the screen with
their eyes.

The id itself is a **named constant declared where the contract lives**, and both
sides import it from there — never a string written into two templates, and never
a generated id (`useId` and friends), which the referring side cannot see.

The sign-in screen is the worked example. The surface is identifier-first, so its
heading changes with the step (`Confirm your email`, `Choose a new password`,
`Your account is ready`), and the modal `HilosView` shows it in has no title of
its own. The heading carries `AUTH_SURFACE_HEADING_ID` (`@hilos/core`), the modal
gets it as `ariaLabelledby`, and the announced name is the very text on screen —
on every step, with no second place to keep in sync.

Two things come with that shape:

- **The fixed `aria-label` stays as the fallback.** `authSurface` is a public
  extension point: a project may mount a surface of its own that carries no such
  heading. Name computation reads `aria-labelledby` first and falls through to
  `aria-label` when it resolves to nothing, so the dialog is named either way —
  by its content when the content offers a name, by the fixed string otherwise.
- **A stable id is unique only while one copy is mounted.** The framework
  guarantees that for the sign-in surface: the modal renders only when the
  surface is not already shown in place. A project that mounts a second copy of
  the surface itself breaks that uniqueness, and fixing it is its own job.

## The application shell

`HilosLayout` (the SDK shell, inherited by all three view layers) carries the
app-wide a11y structure:

- **Skip link** — a `visually-hidden-focusable` link to `#hilos-main-content`,
  the first focusable element, so a keyboard user bypasses the nav.
- **Landmarks** — `<nav aria-label>`, `<main id="hilos-main-content" tabindex="-1">`,
  `<footer>`. The skip link and main landmark are the bypass mechanism.
- **`document.title` on navigation** — set from the navigator's `currentTitle`
  signal so the browser tab names the current page across no-refresh navigation
  (WCAG 2.4.2). Titles come from `bootHilos({ pageTitles, appName })`; see
  [page-registry.md](page-registry.md) and [bootstrap-structure.md](bootstrap-structure.md).
- **Page-change announcement** — a `visually-hidden` `role="status"
  aria-live="polite"` region re-renders the current title, so a screen reader
  hears the page change the no-refresh route swap would otherwise hide.
- **`aria-current="page"`** — `HilosLink` marks itself current when it targets
  the active path (from the core router's `currentPath` signal).
- **`<html lang>`** — each demo's `index.html` declares the page language (3.1.1).

## Component and page patterns

The rules to apply when building a view or an SDK component:

- **One `<h1>` per page; real headings (1.3.1, 2.4.6).** Each routed page exposes
  exactly one top-level heading. Admin pages get it from `HilosAdminPage` (the
  page label), `HilosDashboardPage`, or `HilosStaticPage`; a standalone page owns
  its own. Mark section titles as `<h2>`/`<h3>` — never fake a heading with a bold
  `<span>` or `<strong>`. A page with no visible title still needs the heading;
  use a `visually-hidden` `<h1>` rather than omit it.
- **Never convey meaning by color alone (1.4.1).** A status shown as a colored
  dot or swatch must also carry text. Use a visible text badge where one fits, or
  a `visually-hidden` text span next to a decorative dot; mark the dot
  `aria-hidden="true"`. The presence indicators (online/offline) are the
  reference: a text badge, or a hidden label beside an `aria-hidden` dot.
- **Tables expose their accessibility tree.** `HilosViewportTable` takes a `label`
  → a `visually-hidden` `<caption>` (the table's accessible name); sortable
  headers report `aria-sort` (`none`/`ascending`/`descending`); the search box has
  an `aria-label` (not just a placeholder); the loading row is `role="status"`;
  the Apply control names its pending count; the page indicator reads "Page N of
  M". Name every table through the `label` prop.
- **Icon-only controls** carry an `aria-label`; their `<i class="bi …">` is
  `aria-hidden="true"`.
- **Busy state** — a control performing an action sets `aria-busy` while in
  flight (`LoadingButton`).
- **Live regions** — a live region is a **permanent** node that stands there
  before it has anything to say, and what changes is its **content**. A node
  carrying `aria-live` that is inserted together with its own text guarantees no
  announcement at all: part of the screen readers stay silent, because there was
  no change to a region they were already watching. The visible block that shows
  the same sentence carries **no** role and no `aria-live` of its own — the
  region announces, the block shows; both, and the reader says it twice. Transient
  status that should be announced uses `role="status" aria-live="polite"`. Use
  sparingly, and reserve `role="alert" aria-live="assertive"` for a failure: a
  toast earns the interrupt only at `error` severity, while `success` and `info`
  go polite rather than cut into what the user is listening to. Four references,
  all of them permanent nodes: the page-change announcement and the connection
  indicator (`framework/frontend/vue/src/HilosLayout.vue`), the toast stack
  (`framework/frontend/vue/src/HilosToastHost.vue`) and the sign-in screen
  (`framework/frontend/vue/src/auth/HilosAuthSurface.vue`).
- **A timed notice is readable or it does not exist (2.2.1 Timing Adjustable).**
  A toast that expires before it is read is nothing to a screen-reader user, so
  the toast stack lives 20 seconds — an error does not expire until dismissed —
  and freezes its countdown while it is under the cursor or holds keyboard
  focus, continuing from what is left. That is the success criterion being met,
  not a nicety; a new timed surface owes the same ([toasts.md](toasts.md)).

## What Bootstrap already covers — leave it alone

- **Visible focus** — `:focus-visible` rings on buttons, links, and form
  controls. Do not remove outlines; do not add custom ones.
- **Reduced motion** — Bootstrap guards its own animations per component (and
  disables smooth scroll) under `prefers-reduced-motion: reduce`. The app adds no
  custom animation, so nothing more is needed. Do **not** add a blanket reset.
- **Contrast** — the default theme targets AA. A few stock combinations (muted or
  secondary text on a tinted surface) sit near the 4.5:1 line; tuning them means a
  full Bootstrap Sass recompile, which [styling-rules.md](styling-rules.md) defers
  until a theme actually needs it. Until then, prefer the body and `-emphasis`
  text colors over `-secondary`/`muted` for essential text.

## Testing

Accessibility has its own **rarely-run e2e category**, like the two-window
suite — a separate `a11y.spec.ts` per demo, not part of the inner loop. It
asserts the accessibility tree over the live socket: table accessible names and
`aria-sort`, keyboard sort operability, the skip link and `aria-current`, the
document title and page-change announcement, one top-level heading per page, and
presence exposed as text. Run it in the full pass or pointed
(`test:e2e -- a11y.spec`) while editing a11y; a green inner loop does not require
it. See [testing.md](../testing.md).

## Checklist for a new page or component

1. Exactly one `<h1>`; sub-sections are real `<h2>`/`<h3>`.
2. Every control is a real interactive element with an accessible name;
   icon-only controls have `aria-label` and an `aria-hidden` icon.
3. No information by color alone — pair every status color with text.
4. An edit surface is a `HilosModal` (focus trap, return, Esc, named).
5. A new table is named through `label` and keeps the SDK's ARIA cells.
6. Build from stock Bootstrap classes; add no hand CSS, suppress no focus
   outline, add no `prefers-reduced-motion` override.
7. If the page has a tab title, it flows from `pageTitles`; add the key there
   rather than setting `document.title` by hand.
8. Extend `a11y.spec.ts` for the new structure where it is worth a guard.
9. A live region is declared in advance and stands there permanently; the
   visible block showing the same text does not repeat its role.
