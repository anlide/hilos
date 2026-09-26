import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core'
import { hilosCrumbLinks } from '@hilos/core'
import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'
import { hilosSignal } from './hilosSignal.js'

let headingSequence = 0

/** A catalog heading for the current page, with its breadcrumb and lead. */
@Component({
  selector: 'hilos-page-heading',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosBreadcrumb],
  template: `
    @if (identity(); as page) {
      @if (crumbs().length > 1) {
        <hilos-breadcrumb [crumbs]="crumbs()" />
      }
      <h1 [id]="headingId" class="h4 mb-1" [attr.data-id]="dataId()">
        {{ page.label }}
      </h1>
      @if (page.lead) {
        <p class="text-body-secondary">{{ page.lead }}</p>
      }
    } @else {
      <div
        class="placeholder-glow mb-3"
        [attr.data-id]="dataId() + '-skeleton'"
      >
        <span class="placeholder col-3 d-block mb-2 rounded"></span>
        <span class="placeholder col-6 d-block rounded"></span>
      </div>
    }
  `,
})
export class HilosPageHeading {
  readonly dataId = input('hilos-page-title')
  private readonly router = inject(HILOS_ROUTER)
  protected readonly headingId = `hilos-page-heading-${headingSequence++}`
  protected readonly identity = hilosSignal(this.router.pageIdentity)
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly crumbs = computed(() =>
    hilosCrumbLinks(
      this.identity()?.breadcrumb ?? [],
      this.route().params,
      this.router.resolvePath,
    ),
  )
}
