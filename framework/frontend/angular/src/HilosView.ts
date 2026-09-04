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
import { AUTH_SURFACE_HEADING_ID, subscribeSignal } from '@hilos/core'
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
    @if (showAuthInPlace()) {
      <ng-container [ngComponentOutlet]="authSurfaceType()" />
    } @else if (pageError(); as error) {
      <hilos-error-page [error]="error" />
    } @else if (!pageLoading()) {
      <ng-container [ngComponentOutlet]="view()" />
    }
    <!-- No title of its own: the sign-in surface is identifier-first (HIL-423),
    so what the screen is called changes with the step the person is on, and only
    the surface knows that. It renders its own heading in the body. The dialog is
    still NAMED — the mandated rule (docs/agents/frontend/accessibility.md) has
    every modal expose role=dialog + aria-modal + an accessible name — and it
    takes that name from the very heading the surface draws (HIL-832), so the
    name a screen reader announces is the text a sighted person reads, on every
    step. The fixed ariaLabel stays behind it as the fallback: authSurface is a
    public extension point, and a project surface that carries no such heading
    has to degrade to a dialog named "Sign in", not to a dialog named nothing.

    Never while the same surface is already shown IN PLACE: the gate opens the
    modal for an ack as well as for a gated action (HIL-422), and on a 401'd page
    that would draw a second copy of the surface over the first — two machines,
    two subscriptions, and every control on screen twice. The gate's resume closes
    both states in one move, so nothing is left holding a modal nobody can see. -->
    @if (authSurfaceType() && authGate() && !showAuthInPlace()) {
      <hilos-modal
        [open]="modalOpen()"
        [ariaLabel]="'Sign in'"
        [ariaLabelledby]="headingId"
        [showFooter]="false"
        (cancel)="onModalDismiss()"
      >
        <!-- Gated HERE and not by the modal's own @if around <ng-content/>:
        Angular creates projected content in the view that DECLARES it, so an
        outlet written as modal content would be built the moment this block
        renders and would then live for the life of the page — one surface,
        mounted at boot, with its countdown ticking and its auth_converge
        subscription open, showing whatever screen the last sign-in left behind.
        The Vue peer gets this from a slot behind v-if and the React one from a
        modal that returns null while closed; on this side the open state has to
        be read where the outlet is written. -->
        @if (modalOpen()) {
          <ng-container [ngComponentOutlet]="authSurfaceType()" />
        }
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

  // A module constant is invisible to an Angular template, so the id the auth
  // surface names this modal by reaches the markup through a field.
  protected readonly headingId = AUTH_SURFACE_HEADING_ID

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
  // An anonymous 401 with a surface registered replaces the page with it, and
  // then the modal must stand down: the same surface twice is two machines and
  // two subscriptions racing over one sign-in.
  protected readonly showAuthInPlace = computed<boolean>(
    () => this.pageError()?.httpCode === 401 && !!this.authSurfaceType(),
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
