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
  HilosPageTemplate,
  HilosLink,
  HilosSecondFactorCancelPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosView,
  hilosSignal,
} from '@hilos/angular/base'
import { hilosAdminViews } from '@hilos/angular/admin-views'
import {
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  lazyPage,
  hilosSessionAvatarMark,
  type PageView,
} from '@hilos/core/base'

import { AuthSurface } from './auth/authSurface.js'
import { hilosAuthContext } from './auth/hilosAuthContext.js'
import {
  currentUserId,
  currentUserName,
  currentUserPhoto,
} from './bootstrap/session.js'
import { connection } from './bootstrap/connection.js'
import { PAGE_MAIN } from './pages/keys.js'
import { About } from './views/about/about.js'
import { License } from './views/license/license.js'
import { LicenseDependencies } from './views/license-dependencies/license-dependencies.js'
import { Main } from './views/main/main.js'
import { Privacy } from './views/privacy/privacy.js'
import { PrivacyHistory } from './views/privacy-history/privacy-history.js'
import { Profile } from './views/profile/profile.js'
import { ProfileSignIn } from './views/profile/profile-sign-in.js'
import { ProfileSessions } from './views/profile/profile-sessions.js'
import { ProfileSecurity } from './views/profile/profile-security.js'
import { ProfileAgreements } from './views/profile/profile-agreements.js'
import { ProfileData } from './views/profile/profile-data.js'
import { Terms } from './views/terms/terms.js'
import { TermsHistory } from './views/terms-history/terms-history.js'

@Component({
  selector: 'app-root',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAvatar,
    HilosLayout,
    HilosPageTemplate,
    HilosLink,
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
  template: `<hilos-layout [connection]="connection">
    <span brand>
      <i class="bi bi-ticket-perforated-fill" aria-hidden="true"></i>
      <!-- The name folds down to the icon on a narrow screen and stays the
      link's accessible name there. -->
      <span class="d-none d-md-inline ms-1">Hilos Concert Tickets</span>
      <span class="visually-hidden d-md-none">Hilos Concert Tickets</span>
    </span>
    <ng-container ngProjectAs="[user]">
      @if (userName()) {
        <hilos-notification-bell [connection]="connection" />
        <a
          [hilosLink]="profileHref"
          class="nav-link d-inline-flex align-items-center p-0"
          data-id="nav-profile-name"
          [title]="userName()"
        >
          <hilos-avatar
            [name]="userName()"
            [photo]="userPhoto()"
            [mark]="avatarMark()"
          />
          <span class="visually-hidden" data-id="self-user">{{
            userName()
          }}</span>
          <span data-id="self-user-id" hidden>{{ userId() }}</span>
        </a>
      } @else {
        <!-- A visitor gets one button that opens the surface over the page
        they are standing on. The
        gear a visitor sees on a node in the admin view mode is the shell's own,
        not this slot's. -->
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
    <ng-template hilosPage>
      @if (currentPath() === AUTH_OAUTH_CALLBACK_PATH) {
        <hilos-oauth-callback-page [context]="authContext" />
      } @else if (currentPath() === AUTH_SECOND_FACTOR_CANCEL_PATH) {
        <hilos-second-factor-cancel-page [context]="authContext" />
      } @else {
        <hilos-view
          [pages]="pages"
          [authSurface]="authSurfaceType"
          [authGate]="authGate"
        />
      }
    </ng-template>
  </hilos-layout>`,
})
export class App {
  protected readonly connection = connection

  protected readonly userName = hilosSignal(currentUserName)

  protected readonly userId = hilosSignal(currentUserId)

  protected readonly userPhoto = hilosSignal(currentUserPhoto)

  /** The profile root the avatar leads to. */
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

  // The OAuth callback route (HIL-281) and the second-factor cancel link. Neither
  // carries a page of its own — the address stays a route miss and nothing is
  // subscribed — so App swaps the framework relay view in for the routed outlet
  // while the path matches. The session answer opens the gate the relay
  // dispatches through, and the relay navigates home once it is done. The paths
  // come from @hilos/core (HIL-409): a provider and a mail client enter them, so
  // both halves have to agree on the strings.
  protected readonly currentPath = hilosSignal(inject(HILOS_ROUTER).currentPath)

  protected readonly AUTH_OAUTH_CALLBACK_PATH = AUTH_OAUTH_CALLBACK_PATH

  protected readonly AUTH_SECOND_FACTOR_CANCEL_PATH =
    AUTH_SECOND_FACTOR_CANCEL_PATH

  // The class, not an instance: HilosView mounts it through ngComponentOutlet.
  protected readonly authSurfaceType: Type<unknown> = AuthSurface

  // The page-key → view map HilosView renders from. A page with no mapped view
  // renders nothing; a page the backend does not register is refused by the
  // server, and the outlet draws that refusal instead.
  protected readonly pages: Record<string, PageView<Type<unknown>>> = {
    [PAGE_MAIN]: Main,
    ...hilosAdminViews(),
    [HilosPages.SETTINGS]: lazyPage(() =>
      import('./views/hilos/settings/settings.js').then(
        (module) => module.Settings,
      ),
    ),
    [HilosPages.PROFILE_NOTIFICATIONS]: lazyPage(() =>
      import('./views/hilos/profile-notifications/profile-notifications.js').then(
        (module) => module.ProfileNotifications,
      ),
    ),
    [HilosPages.APPEARANCE]: lazyPage(() =>
      import('./views/hilos/appearance/appearance.js').then(
        (module) => module.Appearance,
      ),
    ),
    [HilosPages.USERS]: lazyPage(() =>
      import('./views/hilos/users/users.js').then((module) => module.Users),
    ),
    [HilosPages.USER]: lazyPage(() =>
      import('./views/hilos/users/user.js').then((module) => module.User),
    ),
    [HilosPages.ANALYTICS]: lazyPage(() =>
      import('./views/hilos/analytics/analytics.js').then(
        (module) => module.Analytics,
      ),
    ),
    [HilosPages.ANALYTICS_BROWSER_SESSION]: lazyPage(() =>
      import('./views/hilos/analytics/browser-session.js').then(
        (module) => module.AnalyticsBrowserSession,
      ),
    ),
    [HilosPages.ANALYTICS_PERSON]: lazyPage(() =>
      import('./views/hilos/analytics/person.js').then(
        (module) => module.AnalyticsPerson,
      ),
    ),
    [HilosPages.ANALYTICS_PEOPLE]: lazyPage(() =>
      import('./views/hilos/analytics/people.js').then(
        (module) => module.AnalyticsPeople,
      ),
    ),
    [HilosPages.ANALYTICS_BROWSER_SESSIONS]: lazyPage(() =>
      import('./views/hilos/analytics/browser-sessions.js').then(
        (module) => module.AnalyticsBrowserSessions,
      ),
    ),
    // The framework Change Log section: the framework owns the tiles, the feed,
    // the tables viewport, the history, the receipt modal and every word; the
    // project binds its connection, page scope and action lifecycle
    // (views/hilos/change-log).
    [HilosPages.CHANGE_LOG]: lazyPage(() =>
      import('./views/hilos/change-log/change-log.js').then(
        (module) => module.ChangeLog,
      ),
    ),
    [HilosPages.CHANGE_LOG_FEED]: lazyPage(() =>
      import('./views/hilos/change-log/change-log-feed.js').then(
        (module) => module.ChangeLogFeed,
      ),
    ),
    [HilosPages.CHANGE_LOG_NOISY]: lazyPage(() =>
      import('./views/hilos/change-log/change-log-noisy.js').then(
        (module) => module.ChangeLogNoisy,
      ),
    ),
    [HilosPages.CHANGE_LOG_TABLES]: lazyPage(() =>
      import('./views/hilos/change-log/change-log-tables.js').then(
        (module) => module.ChangeLogTables,
      ),
    ),
    [HilosPages.CHANGE_LOG_TABLE]: lazyPage(() =>
      import('./views/hilos/change-log/change-log-table.js').then(
        (module) => module.ChangeLogTable,
      ),
    ),
    [HilosPages.CHANGE_LOG_TABLE_COLUMNS]: lazyPage(() =>
      import('./views/hilos/change-log/change-log-table-columns.js').then(
        (module) => module.ChangeLogTableColumns,
      ),
    ),
    [HilosPages.CHANGE_LOG_TABLE_TRIGGERS]: lazyPage(() =>
      import('./views/hilos/change-log/change-log-table-triggers.js').then(
        (module) => module.ChangeLogTableTriggers,
      ),
    ),
    [HilosPages.CHANGE_LOG_TABLE_HISTORY]: lazyPage(() =>
      import('./views/hilos/change-log/change-log-table-history.js').then(
        (module) => module.ChangeLogTableHistory,
      ),
    ),
    // The built Daemon screens bind this project to the SDK contexts.
    [HilosPages.DAEMON]: lazyPage(() =>
      import('./views/hilos/daemon/daemon.js').then((module) => module.Daemon),
    ),
    [HilosPages.DAEMON_PLACEMENT]: lazyPage(() =>
      import('./views/hilos/daemon/placement.js').then(
        (module) => module.DaemonPlacement,
      ),
    ),
    [HilosPages.DAEMON_NODE]: lazyPage(() =>
      import('./views/hilos/daemon/node.js').then(
        (module) => module.DaemonNode,
      ),
    ),
    [HilosPages.DAEMON_WORKERS]: lazyPage(() =>
      import('./views/hilos/daemon/workers.js').then(
        (module) => module.DaemonWorkers,
      ),
    ),
    [HilosPages.DAEMON_AGENTS]: lazyPage(() =>
      import('./views/hilos/daemon/agents.js').then(
        (module) => module.DaemonAgents,
      ),
    ),
    [HilosPages.DAEMON_CRON]: lazyPage(() =>
      import('./views/hilos/daemon/cron.js').then(
        (module) => module.DaemonCron,
      ),
    ),
    [HilosPages.DAEMON_WEBSOCKETS]: lazyPage(() =>
      import('./views/hilos/daemon/websockets.js').then(
        (module) => module.DaemonWebsockets,
      ),
    ),
    [HilosPages.DAEMON_HTTP_SERVER]: lazyPage(() =>
      import('./views/hilos/daemon/http.js').then(
        (module) => module.DaemonHttp,
      ),
    ),
    [HilosPages.DAEMON_ENV]: lazyPage(() =>
      import('./views/hilos/daemon/env.js').then((module) => module.DaemonEnv),
    ),
    [HilosPages.DAEMON_ENV_ORPHANS]: lazyPage(() =>
      import('./views/hilos/daemon/env-orphans.js').then(
        (module) => module.DaemonEnvOrphans,
      ),
    ),
    [HilosPages.DAEMON_ENV_MISMATCH]: lazyPage(() =>
      import('./views/hilos/daemon/env-mismatch.js').then(
        (module) => module.DaemonEnvMismatch,
      ),
    ),
    // The framework logs section, activated whole: the framework owns the six
    // screens, their tables and every phrase on them; the project binds its
    // connection, scope stores and action lifecycle (views/hilos/logs) and, on its
    // backend, the pages, the three agents and the three browser tables.
    [HilosPages.LOGS]: lazyPage(() =>
      import('./views/hilos/logs/overview.js').then(
        (module) => module.LogsOverview,
      ),
    ),
    [HilosPages.LOGS_KEYS]: lazyPage(() =>
      import('./views/hilos/logs/keys.js').then((module) => module.LogKeys),
    ),
    [HilosPages.LOGS_WORKERS]: lazyPage(() =>
      import('./views/hilos/logs/workers.js').then(
        (module) => module.LogWorkers,
      ),
    ),
    [HilosPages.LOGS_ROTATIONS]: lazyPage(() =>
      import('./views/hilos/logs/rotations.js').then(
        (module) => module.LogRotations,
      ),
    ),
    [HilosPages.LOGS_SETTINGS]: lazyPage(() =>
      import('./views/hilos/logs/settings.js').then(
        (module) => module.LogSettings,
      ),
    ),
    [HilosPages.LOGS_VIEW]: lazyPage(() =>
      import('./views/hilos/logs/view.js').then((module) => module.LogViewer),
    ),
    [HilosPages.ABOUT]: About,
    [HilosPages.PROFILE]: Profile,
    [HilosPages.PROFILE_SIGN_IN]: ProfileSignIn,
    [HilosPages.PROFILE_SESSIONS]: ProfileSessions,
    [HilosPages.PROFILE_SECURITY]: ProfileSecurity,
    [HilosPages.PROFILE_AGREEMENTS]: ProfileAgreements,
    [HilosPages.PROFILE_DATA]: ProfileData,
    [HilosPages.TERMS]: Terms,
    [HilosPages.TERMS_HISTORY]: TermsHistory,
    [HilosPages.PRIVACY]: Privacy,
    [HilosPages.PRIVACY_HISTORY]: PrivacyHistory,
    [HilosPages.LICENSE]: License,
    [HilosPages.LICENSE_DEPENDENCIES]: LicenseDependencies,
  }
}
