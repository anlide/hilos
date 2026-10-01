// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand slot and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator.
import { ChangeDetectionStrategy, Component, inject } from '@angular/core'
import type { Type } from '@angular/core'
import {
  HILOS_AUTH_GATE,
  HILOS_ROUTER,
  HilosAvatar,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosSecondFactorCancelPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosView,
  hilosAdminViews,
  hilosSignal,
} from '@hilos/angular'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  hilosSessionAvatarMark,
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
import { Maintenance } from './views/hilos/maintenance/maintenance.js'
import { Main } from './views/main/main'
import { MainSkeleton } from './views/main/main-skeleton'
import { Privacy } from './views/privacy/privacy'
import { SecurityOauth } from './views/hilos/security/oauth'
import { SecurityOauthProvider } from './views/hilos/security/oauth-provider'
import { SecuritySignInMethods } from './views/hilos/security/sign-in-methods'
import { SecurityTwoFactor } from './views/hilos/security/two-factor'
import { Legal } from './views/hilos/legal/legal.js'
import { LegalDocument } from './views/hilos/legal/legal-document.js'
import { LegalRevision } from './views/hilos/legal/legal-revision.js'
import { LegalAcceptances } from './views/hilos/legal/legal-acceptances.js'
import { LegalSettings } from './views/hilos/legal/legal-settings.js'
import { Profile } from './views/profile/profile.js'
import { ProfileSecurity } from './views/profile/profile-security'
import { ProfileData } from './views/profile/profile-data.js'
import { Settings } from './views/hilos/settings/settings'
import { Terms } from './views/terms/terms'
import { User } from './views/hilos/users/user'
import { Users } from './views/hilos/users/users'

@Component({
  selector: 'app-root',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAvatar,
    HilosLayout,
    HilosLink,
    HilosMagicLinkPage,
    HilosSecondFactorCancelPage,
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
    <span brand>Hilos Polls</span>
    <ng-container ngProjectAs="[user]">
      @if (userName()) {
        <hilos-notification-bell [connection]="connection" />
        <a
          [hilosLink]="profileHref"
          class="nav-link d-inline-flex align-items-center p-0"
          data-id="nav-profile-name"
          [title]="userName()"
        >
          <hilos-avatar [name]="userName()" [mark]="avatarMark()" />
          <span class="visually-hidden">{{ userName() }}</span>
        </a>
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
    } @else if (currentPath() === AUTH_SECOND_FACTOR_CANCEL_PATH) {
      <hilos-second-factor-cancel-page [context]="authContext" />
    } @else {
      <hilos-view
        [pages]="pages"
        [pageSkeletons]="pageSkeletons"
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

  /** The profile root the avatar leads to (HIL-1169). */
  protected readonly profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]

  // The standing mark by the avatar (HIL-945): a takeover, or the session's own
  // scheduled deletion, in the color of the strip that says it in words.
  protected readonly avatarMark = hilosSignal(hilosSessionAvatarMark)

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
  protected readonly AUTH_SECOND_FACTOR_CANCEL_PATH =
    AUTH_SECOND_FACTOR_CANCEL_PATH

  protected readonly AUTH_OAUTH_CALLBACK_PATH = AUTH_OAUTH_CALLBACK_PATH

  // The class, not an instance: HilosView mounts it through ngComponentOutlet.
  protected readonly authSurfaceType: Type<unknown> = AuthSurface

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
    // The framework OAuth admin pages (HIL-286): the framework owns the providers,
    // fields and return-address tables and their set / reset round-trips; the
    // project binds its connection, scope stores and action lifecycle
    // (views/hilos/security) and declares its provider directory on the backend.
    [HilosPages.SECURITY_OAUTH]: SecurityOauth,
    [HilosPages.SECURITY_OAUTH_PROVIDER]: SecurityOauthProvider,
    // The framework sign-in methods page (HIL-427): the framework owns the table,
    // the live enabled set and the switch; the project binds its connection, scope
    // stores and action lifecycle (views/hilos/security) and declares its method
    // directory on the backend.
    [HilosPages.SECURITY_SIGN_IN_METHODS]: SecuritySignInMethods,
    [HilosPages.SECURITY_2FA]: SecurityTwoFactor,
    [HilosPages.LEGAL]: Legal,
    [HilosPages.LEGAL_DOCUMENT]: LegalDocument,
    [HilosPages.LEGAL_REVISION]: LegalRevision,
    [HilosPages.LEGAL_ACCEPTANCES]: LegalAcceptances,
    [HilosPages.LEGAL_SETTINGS]: LegalSettings,
    [HilosPages.PROFILE]: Profile,
    [HilosPages.PROFILE_SECURITY]: ProfileSecurity,
    [HilosPages.PROFILE_DATA]: ProfileData,
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
    // Maintenance has no feature switch: the framework owns the circle and both
    // actions; the project binds its context and registers the page and table.
    [HilosPages.MAINTENANCE]: Maintenance,
    [HilosPages.ABOUT]: About,
    [HilosPages.TERMS]: Terms,
    [HilosPages.PRIVACY]: Privacy,
    [HilosPages.LICENSE]: License,
  }

  // The pages that draw a skeleton of their own shape while they wait for their
  // first answer (HIL-983); every other page gets the outlet's default skeleton.
  protected readonly pageSkeletons: Record<string, Type<unknown>> = {
    [PAGE_MAIN]: MainSkeleton,
  }
}
