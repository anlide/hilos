// HilosBreadcrumb — the page-agnostic breadcrumb. It renders a trail of crumbs
// as in-place HilosLink navigation, the last crumb shown as the active page. It
// knows nothing about any specific page: the caller supplies the resolved trail
// (for the Hilos admin tree, hilosCrumbLinks in @hilos/core builds it from the
// chain the page answered with), so the same component serves any page that has a
// breadcrumb. A crumb the route map has no address for is drawn as plain text:
// the chain has to stay whole, and a link to nowhere is worse than no link.
// Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { HilosCrumb } from '@hilos/core'

import { HilosLink } from './HilosLink.js'

/** The page-agnostic breadcrumb trail. */
@Component({
  selector: 'hilos-breadcrumb',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    @if (crumbs().length) {
      <nav aria-label="breadcrumb" data-id="hilos-breadcrumb">
        <ol class="breadcrumb small mb-2">
          @for (crumb of crumbs(); track crumb.page; let last = $last) {
            @if (last) {
              <li class="breadcrumb-item active" aria-current="page">
                {{ crumb.label }}
              </li>
            } @else {
              <li class="breadcrumb-item">
                @if (crumb.to; as to) {
                  <a [hilosLink]="to">{{ crumb.label }}</a>
                } @else {
                  {{ crumb.label }}
                }
              </li>
            }
          }
        </ol>
      </nav>
    }
  `,
})
export class HilosBreadcrumb {
  /** The resolved trail; the last entry renders as the active, non-link page. */
  readonly crumbs = input.required<HilosCrumb[]>()
}
