// HilosView — the routed outlet (the `<router-outlet>` of the SDK). It mirrors
// the core navigator's current route and renders the component the project
// mapped to that page key, swapping it in place as navigation changes the
// route. A page subscription error (subscription_page_error) takes precedence
// over the mapped component: the full-page ErrorPage shows instead. An unmapped
// page renders nothing. The router must be provided at the app root
// (HILOS_ROUTER).
//
// A page is held back until its subscription answers (router.pageLoading):
// showing it earlier means showing a page the server may be about to deny, and
// taking it away again one round trip later. The `hilos-page-state` marker names
// the outcome — loading, error or ready — so the state is readable from the DOM
// rather than guessed from whichever element happened to render first.
//
// It also hosts the auth gate (HIL-165): when the project registers an
// `authSurface`, an anonymous 401 mounts that surface IN PLACE of ErrorPage, and
// the `authGate`'s modal shows the same surface over the live page for a gated
// action. Both dismiss and resume through the core gate — no navigation. Omit
// the pair and behavior is unchanged: a 401 renders ErrorPage like any status.
import { NgComponentOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import type { Type } from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type { AuthGate } from '@hilos/core'

import { ErrorPage } from './ErrorPage.js'
import { HilosModal } from './HilosModal.js'
import { hilosSignal } from './hilosSignal.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'

/**
 * Renders the component mapped to the navigator's current page, the sign-in
 * surface when the page is gated by an anonymous 401, or the full-page error
 * surface for any other subscription error.
 */
@Component({
  selector: 'hilos-view',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgComponentOutlet, ErrorPage, HilosModal],
  template: `
    <div
      data-id="hilos-page-state"
      [attr.data-state]="pageState()"
      hidden
    ></div>
    @if (pageError(); as error) {
      @if (error.httpCode === 401 && authSurfaceType()) {
        <ng-container [ngComponentOutlet]="authSurfaceType()" />
      } @else {
        <hilos-error-page [error]="error" />
      }
    } @else if (!pageLoading()) {
      <ng-container [ngComponentOutlet]="view()" />
    }
    @if (authSurfaceType() && authGate()) {
      <hilos-modal
        [open]="modalOpen()"
        title="Sign in"
        (cancel)="onModalDismiss()"
      >
        <ng-container [ngComponentOutlet]="authSurfaceType()" />
        <ng-template #modalActions />
      </hilos-modal>
    }
  `,
})
export class HilosView {
  /** Maps a page key to the component rendered while that page is current. */
  readonly pages = input.required<Record<string, Type<unknown>>>()
  /**
   * The project's sign-in surface (HIL-364), mounted in place of ErrorPage on an
   * anonymous 401 and inside the auth modal for a gated action. Omit it and a
   * 401 renders ErrorPage.
   */
  readonly authSurface = input<Type<unknown>>()
  /** The auth gate driving the sign-in modal and the resume-on-auth. */
  readonly authGate = input<AuthGate>()

  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly pageError = hilosSignal(this.router.pageError)
  protected readonly pageLoading = hilosSignal(this.router.pageLoading)

  // The one place the three outcomes of a navigation are named. The marker
  // element carries it so a test waits for the page to be settled instead of
  // polling for an element that renders before the answer and disappears when
  // it lands.
  protected readonly pageState = computed<string>(() =>
    this.pageError() ? 'error' : this.pageLoading() ? 'loading' : 'ready',
  )

  protected readonly view = computed<Type<unknown> | null>(
    () => this.pages()[this.route().page] ?? null,
  )
  protected readonly authSurfaceType = computed<Type<unknown> | null>(
    () => this.authSurface() ?? null,
  )
  // The core modal-open signal mirrored into an Angular signal: the effect
  // (re)subscribes when the gate input arrives or changes and cleans up on
  // destroy, so a duplicated reactivity engine stays harmless (multiframework).
  protected readonly modalOpen = signal(false)

  constructor() {
    effect((onCleanup) => {
      const gate = this.authGate()
      if (!gate) {
        this.modalOpen.set(false)

        return
      }
      this.modalOpen.set(gate.modalOpen.get())
      onCleanup(
        subscribeSignal(gate.modalOpen, (open) => this.modalOpen.set(open)),
      )
    })
  }

  // The modal only ever closes from within (Esc/backdrop/close button); route
  // that back through the gate so its state stays the single source of truth.
  protected onModalDismiss(): void {
    this.authGate()?.dismiss()
  }
}
