// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand slot and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator.
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  signal,
} from '@angular/core'
import type { Type } from '@angular/core'
import {
  HILOS_AUTH_GATE,
  HILOS_ROUTER,
  HilosLayout,
  HilosMagicLinkPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosView,
  hilosAdminViews,
  hilosSignal,
} from '@hilos/angular'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HilosPages,
} from '@hilos/core'

import { AuthSurface } from './auth/authSurface'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import { currentUserIsAdmin, currentUserName } from './bootstrap/session'
import { PAGE_MAIN } from './pages/keys'
import { About } from './views/about/about'
import { License } from './views/license/license'
import { LogKeys } from './views/hilos/logs/keys'
import { LogRotations } from './views/hilos/logs/rotations'
import { LogSettings } from './views/hilos/logs/settings'
import { LogViewer } from './views/hilos/logs/view'
import { LogWorkers } from './views/hilos/logs/workers'
import { LogsOverview } from './views/hilos/logs/overview'
import { Main } from './views/main/main'
import { Privacy } from './views/privacy/privacy'
import { Settings } from './views/hilos/settings/settings'
import { Terms } from './views/terms/terms'
import { User } from './views/hilos/users/user'
import { Users } from './views/hilos/users/users'

// The framework sign-out action (HIL-710): signing out writes a session, so it is
// the sessions library that owns the command and this demo declares nothing for
// it. It is page-independent — the control lives in the shell — so it goes over
// the agent action rather than a page action.
const LOGOUT_ACTION = 'hilos_logout'

// Releases the sign-out button if the broadcast never arrives, so the control can
// never wedge on a dropped frame.
const LOGOUT_FALLBACK_MS = 5000

@Component({
  selector: 'app-root',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosLayout,
    HilosMagicLinkPage,
    HilosNotificationBell,
    HilosOAuthCallbackPage,
    HilosView,
  ],
  // The user region is ONE projected node, not two: the shell's slot is
  // `<ng-content select="[user]">`, which matches the root nodes of what is
  // projected, and a conditional block sitting on that boundary is the known
  // Angular trap. ngProjectAs names the slot explicitly and the branches live
  // safely inside it.
  template: `<hilos-layout [connection]="connection" [isAdmin]="isAdmin()">
    <span brand>Hilos Poll</span>
    <ng-container ngProjectAs="[user]">
      @if (userName()) {
        <hilos-notification-bell [connection]="connection" />
        <span class="small" data-id="nav-profile-name">
          <i class="bi bi-person-circle me-1" aria-hidden="true"></i>
          {{ userName() }}
        </span>
        <button
          type="button"
          class="btn btn-link nav-link d-inline-flex align-items-center p-0 ms-3"
          data-id="nav-logout"
          aria-label="Log out"
          [disabled]="loggingOut()"
          (click)="logout()"
        >
          @if (loggingOut()) {
            <span
              class="spinner-border spinner-border-sm"
              role="status"
              aria-hidden="true"
            ></span>
          } @else {
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
          }
        </button>
      } @else {
        <!-- A visitor gets neither bell nor gear — there is nothing to show —
        and one button that opens the surface over the page they are standing on
        (mockups/framework/layout, the "guest" tile). -->
        <button
          type="button"
          class="btn btn-sm btn-primary"
          data-id="nav-signin"
          (click)="authGate.requireAuth()"
        >
          Sign in
        </button>
      }
    </ng-container>
    @if (currentPath() === AUTH_MAGIC_LINK_PATH) {
      <hilos-magic-link-page [context]="authContext" />
    } @else if (currentPath() === AUTH_OAUTH_CALLBACK_PATH) {
      <hilos-oauth-callback-page [context]="authContext" />
    } @else {
      <hilos-view
        [pages]="pages"
        [authSurface]="authSurfaceType"
        [authGate]="authGate"
      />
    }
  </hilos-layout>`,
})
export class App {
  protected readonly connection = connection

  protected readonly isAdmin = hilosSignal(currentUserIsAdmin)

  protected readonly userName = hilosSignal(currentUserName)

  /**
   * The application's auth gate. Injected and not taken as an input: the root
   * component of `bootstrapApplication` accepts none, and the shell's Sign in
   * button calls the gate directly while HilosView needs it to open the sign-in
   * modal over a live page.
   */
  protected readonly authGate = inject(HILOS_AUTH_GATE)

  protected readonly authContext = hilosAuthContext

  // The magic-link confirm route (HIL-283) and the OAuth callback route
  // (HIL-281). Neither carries a page of its own — the router falls both back to
  // the main subscription so their actions route — so App swaps the framework
  // relay view in for the routed outlet while the path matches, and the relay
  // navigates home once the session upgrades. The paths come from @hilos/core
  // (HIL-409): a mail client and a provider enter them, so both halves have to
  // agree on the strings.
  protected readonly currentPath = hilosSignal(inject(HILOS_ROUTER).currentPath)

  protected readonly AUTH_MAGIC_LINK_PATH = AUTH_MAGIC_LINK_PATH

  protected readonly AUTH_OAUTH_CALLBACK_PATH = AUTH_OAUTH_CALLBACK_PATH

  // The class, not an instance: HilosView mounts it through ngComponentOutlet.
  protected readonly authSurfaceType: Type<unknown> = AuthSurface

  // The clicker gets loading while sign-out is in flight: the button enters
  // `loggingOut` on send and leaves it when the broadcast lands — the session
  // downgrade drops `userName`, which both un-loads and (through its own
  // condition) removes the control, the visible confirmation.
  protected readonly loggingOut = signal(false)

  // The fallback timer's handle, kept so it is cleared the moment the broadcast
  // ends loading. Without clearing it, a stale timer from one sign-out could fire
  // during a later one and drop its loading early.
  private fallbackTimer: ReturnType<typeof setTimeout> | undefined = undefined

  // The page-key → view map HilosView renders from. Pages without a mapped view
  // (other routes land later) render nothing.
  protected readonly pages: Record<string, Type<unknown>> = {
    [PAGE_MAIN]: Main,
    // The Hilos admin section. The framework ships a real default page for every
    // admin key (hilosAdminViews) — including the dashboard — so the demo maps only
    // the pages it implements itself; the rest render the framework default, never
    // recopied per project (page-module-structure.md).
    ...hilosAdminViews(),
    // The framework settings admin page, activated configure-only: the framework
    // owns the table and the add/update/delete lifecycle; the project binds only its
    // scope stores + action lifecycle (views/hilos/settings) and its catalog on the backend.
    [HilosPages.SETTINGS]: Settings,
    // The framework users/user admin pages: the framework owns the table, the
    // detail, and the rename round-trip; the project binds its scope stores,
    // connection, and typed user collection (views/hilos/users) and supplies its user
    // entity + presence sources on the backend.
    [HilosPages.USERS]: Users,
    [HilosPages.USER]: User,
    // The framework logs section, activated whole: the framework owns the six
    // screens, their tables and every phrase on them; the project binds its
    // connection, scope stores and action lifecycle (views/hilos/logs) and, on its
    // backend, the pages, the three agents and the three browser tables.
    [HilosPages.LOGS]: LogsOverview,
    [HilosPages.LOGS_KEYS]: LogKeys,
    [HilosPages.LOGS_WORKERS]: LogWorkers,
    [HilosPages.LOGS_ROTATIONS]: LogRotations,
    [HilosPages.LOGS_SETTINGS]: LogSettings,
    [HilosPages.LOGS_VIEW]: LogViewer,
    [HilosPages.ABOUT]: About,
    [HilosPages.TERMS]: Terms,
    [HilosPages.PRIVACY]: Privacy,
    [HilosPages.LICENSE]: License,
  }

  constructor() {
    // React to the broadcast: the downgrade clears the name, which ends loading
    // and cancels the now-unnecessary fallback timer.
    effect(() => {
      if (this.userName()) {
        return
      }
      this.loggingOut.set(false)
      this.clearFallbackTimer()
    })
  }

  /** Sends the sign-out command and holds the control until the broadcast lands. */
  protected logout(): void {
    if (this.loggingOut()) {
      return
    }
    this.loggingOut.set(true)
    if (!this.connection.sendAction(LOGOUT_ACTION, {})) {
      // Not sent (the socket is down): the action never left, so do not show
      // loading for a broadcast that will never come.
      this.loggingOut.set(false)

      return
    }
    this.fallbackTimer = setTimeout(() => {
      this.loggingOut.set(false)
      this.fallbackTimer = undefined
    }, LOGOUT_FALLBACK_MS)
  }

  private clearFallbackTimer(): void {
    if (this.fallbackTimer !== undefined) {
      clearTimeout(this.fallbackTimer)
      this.fallbackTimer = undefined
    }
  }
}
