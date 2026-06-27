// HilosView — the routed outlet (the `<router-outlet>` of the SDK). It mirrors
// the core navigator's current route and renders the component the project
// mapped to that page key, swapping it in place as navigation changes the
// route. A page subscription error (subscription_page_error) takes precedence
// over the mapped component: the full-page ErrorPage shows instead. An unmapped
// page renders nothing. The router must be provided at the app root
// (HILOS_ROUTER).
import { NgComponentOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core'
import type { Type } from '@angular/core'

import { ErrorPage } from './ErrorPage.js'
import { hilosSignal } from './hilosSignal.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'

/**
 * Renders the component mapped to the navigator's current page, or the full-page
 * error surface when the page carries a subscription error.
 */
@Component({
  selector: 'hilos-view',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgComponentOutlet, ErrorPage],
  template: `
    @if (pageError(); as error) {
      <hilos-error-page [error]="error" />
    } @else {
      <ng-container [ngComponentOutlet]="view()" />
    }
  `,
})
export class HilosView {
  /** Maps a page key to the component rendered while that page is current. */
  readonly pages = input.required<Record<string, Type<unknown>>>()

  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly pageError = hilosSignal(this.router.pageError)

  protected readonly view = computed<Type<unknown> | null>(
    () => this.pages()[this.route().page] ?? null,
  )
}
