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

- inline `style` attributes, in every spelling a view framework offers them —
  Vue's `style` / `:style` / `v-bind:style`, Angular's `style` / `[style]` /
  `[style.prop]` / `[ngStyle]`, React's `style={…}`;
- writing a declaration onto an element from code — `el.style.<prop> = …`,
  `el.style.setProperty('<prop>', …)`, `el.style.cssText = …`;
- a Vue SFC trailing `<style>` block (the strict reading of "no CSS at the end of
  the file" is intended — an SFC carries no `<style>` at all);
- an Angular component declaring `styles:` or `styleUrls:` of its own;
- global stylesheets;
- any hand-authored `.css` file in app code.

The imperative write is named out loud rather than left to be inferred from
"inline `style` attribute". It lands in the same place through a different door,
and there are zero of them in the tree today — so naming it costs nothing now and
closes the way around the rule before anyone walks it.

The reason is structural: an SFC `<style>` or inline style would let hardcoded
colors and sizes bypass Bootstrap's theme variables and responsive breakpoints,
breaking theming and mobile support out of the box. The cost — utility-class
verbosity, and an awkward home for the rare bespoke rule — is accepted
consciously.

Checked automatically: `STYLE-INLINE` for the two inline forms — the attribute
and the imperative write — and `STYLE-SHEET-HOME` for the other three, which are
one violation wearing three faces: a declaration housed outside the layer.

## The Sass layer — the only home for custom declarations

Customization lives **only** in the Bootstrap Sass layer: variables, maps, and
the custom-utilities API. This is what makes the ban feasible — there is always a
Bootstrap-native way to express a need. Each custom declaration carries a comment
stating **why** Bootstrap utilities cannot achieve it and **what** it is for. Even
a rare bespoke rule (e.g. a keyframe) goes here, documented — never as an ad-hoc
escape hatch elsewhere.

Checked automatically: `STYLE-SHEET-HOME`. The layer is a list of sanctioned
paths — one file per view package — and a stylesheet anywhere else is reported by
its path alone, whether or not anything imports it.

## A computed value — the one legal channel

A number the template computes still has to reach CSS, and no class can express
one: a progress bar's width is the honest example. The one legal channel is a
**CSS custom property set on the element**, and this document says out loud what
it would be dishonest to hide — that a custom property on an element **is** an
inline style. It is legal because of what it carries, not because of where it is
written:

```vue
<div class="progress hilos-progress" :style="{ '--hilos-progress': percent }">
```

The element hands over a number and nothing else. The rule that consumes it lives
in the Sass layer beside its `WHY` comment, like every other declaration stock
utilities cannot express:

```scss
// WHY: Bootstrap's progress bar takes its width from an inline style, and a
// width computed per frame has no class to be expressed as.
// WHAT: the width of a progress bar whose owner hands over a percentage.
.hilos-progress {
  width: calc(var(--hilos-progress) * 1%);
}
```

The channel is narrowed to a custom property rather than opened to "a dynamic
binding" on purpose. A flat ban cannot express a progress bar; legalizing dynamic
bindings wholesale would leave every static style one interpolation away from
legal. What is judged is the **name** of every property a site sets, so no
spelling launders a normal property through a variable — and a set of names that
cannot be read where it is written (an identifier handed to `:style` instead of
an object literal) is a violation of its own, because passing it would make the
ban one indirection deep.

## Where Bootstrap lives — the SDK ships it

Bootstrap is **not** a per-project dependency. The framework **view layers**
(`@hilos/vue`, and later `@hilos/react` / `@hilos/angular`) depend on Bootstrap
and pull in its stylesheet, so every consumer is styled **transitively** and
**never declares or imports Bootstrap itself**. "Bootstrap everywhere" means
every *view* layer — not the agnostic core: `@hilos/core` stays pure TypeScript
with no styling, both because it never renders and because keeping it CSS-free
preserves the option to publish it as a standalone pure-JS package
([sdk-packaging.md](sdk-packaging.md)).

A view layer's entry imports Bootstrap for its side effect, and the library
build inlines the stylesheet into the shipped bundle, so importing `@hilos/vue`
is styled with no extra step in both dev (the consumer resolves the SDK to
source) and the built artifact (the consumer resolves it to `dist`).

**Bootstrap Icons** ship the same way: a view layer depends on `bootstrap-icons`
and its entry side-effect-imports `bootstrap-icons/font/bootstrap-icons.css`, so
the `bi bi-*` icon classes are available transitively and a consumer never
declares the icon package. SDK components use the icon font (`<i class="bi
bi-…">`), not inline SVG — the application shell's gear and connection indicator
are the reference. The icon font carries its own woff2/woff assets; the consumer
build resolving the SDK to source emits and rewrites those font URLs, so no
per-project font wiring is needed.

**Angular is the exception** to "the consumer never declares Bootstrap": the
Angular CLI delivers global CSS through `angular.json` `styles`, with no
transitive-stylesheet mechanism a library can drive (and an ng-packagr FESM
cannot side-effect-import global CSS the way a Vite-built bundle does). So
`@hilos/angular` declares `bootstrap` and `bootstrap-icons` as **peer**
dependencies — the requirement — and the Angular app fulfills them: it lists
both packages and references their stylesheets in `angular.json` `styles`
(`node_modules/bootstrap/dist/css/bootstrap.min.css` and
`bootstrap-icons/font/bootstrap-icons.css`). The components are identical to the
Vue/React shells; only the CSS-delivery channel differs.

The accepted trade: build-time Sass customization (variable maps, custom
utilities — the Sass layer above) becomes a **framework** concern, not a
per-project one. A project still themes at runtime with `data-bs-theme` and
CSS-variable overrides. The view layer imports Bootstrap's **compiled**
stylesheet directly and loads a thin Sass layer (`hilos-styles.scss`) **after**
it for the few documented declarations stock utilities cannot express — today
only `.min-h-0` (`min-height: 0`), the lever a flex child needs to own its own
scroll (the app shell's main region and the chat message list), which Bootstrap
ships no utility for. A full Sass re-compile of Bootstrap (overriding its
variable and map defaults) stays deferred until a theme actually needs it; the
thin layer covers the exceptions without it. Components otherwise depend only on
stock Bootstrap classes, never on declarations a consumer would supply.

**Angular delivers the layer consumer-side.** Because `@hilos/angular` cannot
ship transitive CSS (ng-packagr emits no side-effect stylesheet), the Vue and
React view layers side-effect-import `hilos-styles.scss` from their entry, while
an Angular app lists **the SDK's own file** in its `angular.json` `styles`
(`node_modules/@hilos/angular/src/hilos-styles.scss`) alongside Bootstrap — the
documented Angular exception to "the consumer never wires styling", mirroring
how Angular already delivers Bootstrap itself. The app carries no copy of the
rules: the layer belongs to the framework in all three view packages, and only
the channel that loads it differs.

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

## Accessibility — full AA, in v1

Accessibility ships from day one at **WCAG 2.1 AA**, not as a later bolt-on, and
it leans directly on this styling rule: building from stock Bootstrap 5.3 classes
delivers the visual a11y layer for free — `:focus-visible` focus rings, a nuanced
`prefers-reduced-motion` story (spinners slow, they do not freeze), and an
AA-tuned theme — so hand-authored CSS would only regress it (a blanket
reduced-motion reset, for instance, freezes the loading spinners). Hilos adds the
structure on top: landmarks, one heading per page, ARIA roles and names, text
alternatives for status color, focus-trapped modals, and the live regions.

The normative checklist — the four pillars, the app-shell layer, the per-component
patterns, what Bootstrap already covers, and the new-page checklist — is
[accessibility.md](accessibility.md). Read it before building or reviewing any
view or SDK component.
