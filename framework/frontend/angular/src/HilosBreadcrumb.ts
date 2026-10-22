// HilosBreadcrumb — the page-agnostic breadcrumb. It renders a trail of crumbs
// as in-place HilosLink navigation, the last crumb shown as the active page. It
// knows nothing about any specific page: the caller supplies the resolved trail
// (for the Hilos admin tree, hilosCrumbLinks in @hilos/core builds it from the
// chain the page answered with), so the same component serves any page that has a
// breadcrumb. A crumb the route map has no address for is drawn as plain text:
// the chain has to stay whole, and a link to nowhere is worse than no link. The
// last crumb alone is current; no link takes that mark from its own path match.
// The navigation's accessible name is the caller's translatable label,
// 'breadcrumb' when it gives none. The items carry no breadcrumb-item class,
// whose ::before slash a screen reader may speak: utilities draw the same look,
// and the slash between two crumbs is a span the screen reader skips. A long
// crumb breaks onto the next line. Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosCrumb } from '@hilos/core/base'

import { HilosLink } from './HilosLink.js'

/** The page-agnostic breadcrumb trail. */
@Component({
  selector: 'hilos-breadcrumb',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    @if (crumbs().length) {
      <nav [attr.aria-label]="label()" data-id="hilos-breadcrumb">
        <ol class="breadcrumb small mb-2 text-break">
          @for (
            crumb of crumbs();
            track crumb.page;
            let first = $first, last = $last
          ) {
            <li
              [class.ps-2]="!first"
              [class.text-body-secondary]="last"
              [attr.aria-current]="last ? 'page' : null"
            >
              @if (!first) {
                <span
                  class="float-start pe-2 text-body-secondary"
                  aria-hidden="true"
                  >/</span
                >
              }
              @if (last) {
                {{ crumb.label }}
              } @else if (crumb.to; as to) {
                <a
                  [hilosLink]="to"
                  [hilosLinkCurrent]="null"
                  [attr.data-id]="'hilos-breadcrumb-' + crumb.page"
                  >{{ crumb.label }}</a
                >
              } @else {
                {{ crumb.label }}
              }
            </li>
          }
        </ol>
      </nav>
    }
  `,
})
export class HilosBreadcrumb {
  /** The resolved trail; the last entry renders as the active, non-link page. */
  readonly crumbs = input.required<HilosCrumb[]>()

  /** The navigation's accessible name. */
  readonly label = input('breadcrumb')
}
