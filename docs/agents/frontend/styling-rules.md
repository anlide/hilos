# Styling Rules

All styling is expressed with Bootstrap classes. Hand-authored CSS anywhere in
app code is a gross Hilos violation; the one home for custom style declarations
is the Bootstrap Sass layer. This single forcing function buys theming and
responsive behavior for free, and is a deliberate, accepted trade.

## Bootstrap classes only

Express all styling with Bootstrap utility and component classes, twisted to
whatever complexity a layout needs. The template is the only place styling is
declared.

## What is forbidden

The following are **gross Hilos violations**, no exceptions:

- inline `style` attributes;
- a Vue SFC trailing `<style>` block (the strict reading of "no CSS at the end of
  the file" is intended — an SFC carries no `<style>` at all);
- global stylesheets;
- any hand-authored `.css` file in app code.

The reason is structural: an SFC `<style>` or inline style would let hardcoded
colors and sizes bypass Bootstrap's theme variables and responsive breakpoints,
breaking theming and mobile support out of the box. The cost — utility-class
verbosity, and an awkward home for the rare bespoke rule — is accepted
consciously.

## The Sass layer — the only home for custom declarations

Customization lives **only** in the Bootstrap Sass layer: variables, maps, and
the custom-utilities API. This is what makes the ban feasible — there is always a
Bootstrap-native way to express a need. Each custom declaration carries a comment
stating **why** Bootstrap utilities cannot achieve it and **what** it is for. Even
a rare bespoke rule (e.g. a keyframe) goes here, documented — never as an ad-hoc
escape hatch elsewhere.

## Theming

Theming uses Bootstrap 5.3 `data-bs-theme` plus CSS variables. A theme that needs
a small `:root` / `[data-bs-theme]` variable override expresses it in the Sass
layer as a documented exception — that is the intended mechanism, not global CSS
sprawl. Theme switching need not be implemented for the rule to pay off: holding
the rule makes the app themeable for free.

## Responsive and mobile

Mobile and desktop compatibility comes for free when the rule holds — Bootstrap's
responsive utilities and breakpoints cover it. v1 is responsive out of the box;
there is no first-class touch-specific design.

## Accessibility — full, in v1

Accessibility ships from day one, out of the box — a serious framework supports it
without trouble. The rules are not complex:

- **focus-trap and focus-return** in modals (ties to edit-in-modal,
  `conflict-resolution.md`);
- **full keyboard navigation** of every interactive element;
- **ARIA** roles and labels;
- **visible focus** and adequate **contrast** (via the Bootstrap theme).

Accessibility is not deferred or bolted on later; it is part of every component
from the start.
