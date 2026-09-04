---
name: hilos-frontend-accessibility
description: Build or review an accessible Hilos frontend surface to WCAG 2.1 AA. Use when adding, porting, or reviewing a view, page, or SDK component for accessibility — headings, ARIA roles and names, keyboard operability, focus management, text alternatives for status color, the application-shell a11y layer (skip link, document title, page-change announcement) — or when tempted to add CSS for focus, reduced motion, or contrast.
---

# Hilos Frontend Accessibility

Use this skill whenever you build, port, or review a frontend surface for
accessibility. Start with `agents.md`, then read the canonical checklist. The
target is **WCAG 2.1 AA**, in full, from v1.

## Read First

- The normative AA checklist (canonical): `docs/agents/frontend/accessibility.md`
- Bootstrap-only styling — the no-hand-CSS rule a11y leans on: `docs/agents/frontend/styling-rules.md`
- Focus-trapped modal editing (the edit-surface pillar): `docs/agents/frontend/conflict-resolution.md`
- The rare a11y e2e category: `docs/agents/testing.md`
- Components: `framework/frontend/{vue,react,angular}/src/*` — `HilosLayout`,
  `HilosModal`, `HilosViewportTable`, `LoadingButton`, `HilosLink`
- How the file itself must look once the a11y shape is decided:
  `$hilos-code-style-typescript` (and its `-vue` / `-react` / `-angular` wrapper)

## Workflow

1. Lean on Bootstrap 5.3 for the visual layer — `:focus-visible` rings,
   per-component reduced motion, the AA-tuned theme — by building from stock
   classes. Add no hand CSS.
2. Give every page exactly one `<h1>`; mark sections as real `<h2>`/`<h3>`. Admin
   pages inherit the `<h1>` from `HilosAdminPage` / `HilosDashboardPage` /
   `HilosStaticPage`; a standalone page owns its own (a `visually-hidden` `<h1>`
   when there is no visible title).
3. Make every control a real interactive element with an accessible name;
   icon-only controls get an `aria-label` and an `aria-hidden` icon.
4. Never convey a status by color alone — pair the color with text: a visible
   badge, or a `visually-hidden` span beside a decorative `aria-hidden` dot.
5. Edit only inside a focus-trapped `HilosModal`; name every table through its
   `label`; set `aria-busy` on an in-flight button; announce transient status
   through `role="status" aria-live="polite"`, sparingly.
6. Let a page's tab title flow from `pageTitles` (the bootstrap input), not a
   hand-set `document.title`.
7. Guard new structure in the demo's `a11y.spec.ts` (the rare category); run it
   pointed with `test:e2e -- a11y.spec`.

## Hard Rules

- Target WCAG 2.1 AA; do not defer or bolt it on later.
- No hand-authored CSS for focus, reduced motion, or contrast — it regresses
  Bootstrap and violates `styling-rules.md` (checked by `STYLE-SHEET-HOME` and
  `STYLE-INLINE`). Never add a blanket `prefers-reduced-motion` reset; it freezes
  the loading spinners.
- A value the code computes — a progress width, a meter's fill — reaches CSS
  through one channel and no other: a CSS custom property set on the element,
  with the rule that consumes it living in the Sass layer beside its `WHY`
  comment. Every other property name written inline is a violation
  (`styling-rules.md`).
- No information by color alone; never suppress a focus outline.
- One top-level heading per page; never fake a heading with bold text.

## Contract Gate

None. Accessibility is presentation and structure and touches no wire contract.
The page-title source (`pageTitles` / `appName`) is a bootstrap input, not a
signal, route, or entity shape, so it is not contract-gated.
