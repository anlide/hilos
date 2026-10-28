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
  hilosSignal,
} from '@hilos/angular/base'
import { hilosAdminViews } from '@hilos/angular/admin-views'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  lazyPage,
  type PageView,
  hilosSessionAvatarMark,
} from '@hilos/core/base'

import { AuthSurface } from './auth/authSurface'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import { currentUserName, currentUserPhoto } from './bootstrap/session.js'
import { PAGE_MAIN } from './pages/keys'
import { About } from './views/about/about'
import { License } from './views/license/license'
import { Main } from './views/main/main'
import { MainSkeleton } from './views/main/main-skeleton'
import { Privacy } from './views/privacy/privacy'
import { Profile } from './views/profile/profile.js'
import { ProfileSignIn } from './views/profile/profile-sign-in.js'
import { ProfileSessions } from './views/profile/profile-sessions.js'
import { ProfileSecurity } from './views/profile/profile-security.js'
import { ProfileAgreements } from './views/profile/profile-agreements.js'
import { ProfileAgreementsHistory } from './views/profile/profile-agreements-history.js'
import { ProfileData } from './views/profile/profile-data.js'
import { Terms } from './views/terms/terms'

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
  template: `<hilos-layout [connection]="connection">
    <span brand>
      <i class="bi bi-bar-chart" aria-hidden="true"></i>
      <span class="d-none d-md-inline ms-1">Hilos Polls</span>
      <span class="visually-hidden d-md-none">Hilos Polls</span>
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
          <span class="visually-hidden">{{ userName() }}</span>
        </a>
      } @else {
        <!-- A visitor gets no bell — there is nothing to show — and one button
        that opens the surface over the page they are standing on
        (mockups/framework/layout, the "guest" tile). The gear a visitor sees on
        a node in the admin view mode is the shell's own, not this slot's. -->
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

  protected readonly userName = hilosSignal(currentUserName)
  protected readonly userPhoto = hilosSignal(currentUserPhoto)

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
  // (HIL-281). Neither carries a page of its own — the address stays a route
  // miss and nothing is subscribed — so App swaps the framework relay view in
  // for the routed outlet while the path matches. The session answer opens the
  // gate the relay dispatches through, and the relay navigates home once the
  // session upgrades. The paths come from @hilos/core (HIL-409): a mail client
  // and a provider enter them, so both halves have to agree on the strings.
  protected readonly currentPath = hilosSignal(inject(HILOS_ROUTER).currentPath)

  protected readonly AUTH_MAGIC_LINK_PATH = AUTH_MAGIC_LINK_PATH
  protected readonly AUTH_SECOND_FACTOR_CANCEL_PATH =
    AUTH_SECOND_FACTOR_CANCEL_PATH

  protected readonly AUTH_OAUTH_CALLBACK_PATH = AUTH_OAUTH_CALLBACK_PATH

  // The class, not an instance: HilosView mounts it through ngComponentOutlet.
  protected readonly authSurfaceType: Type<unknown> = AuthSurface

  // The page-key → view map HilosView renders from. Pages without a mapped view
  // (other routes land later) render nothing.
  protected readonly pages: Record<string, PageView<Type<unknown>>> = {
    [PAGE_MAIN]: Main,
    // The Hilos admin section. The framework ships a real default page for every
    // admin key (hilosAdminViews) — including the dashboard — so the demo maps only
    // the pages it implements itself; the rest render the framework default, never
    // recopied per project (page-module-structure.md).
    ...hilosAdminViews(),
    // The framework settings admin page, activated configure-only: the framework
    // owns the table and the add/update/delete lifecycle; the project binds only its
    // scope stores + action lifecycle (views/hilos/settings) and its catalog on the backend.
    [HilosPages.SETTINGS]: lazyPage(() =>
      import('./views/hilos/settings/settings.js').then(
        (module) => module.Settings,
      ),
    ),
    [HilosPages.COMMUNICATIONS]: lazyPage(() =>
      import('./views/hilos/communications/communications.js').then(
        (module) => module.Communications,
      ),
    ),
    [HilosPages.COMMUNICATIONS_CHANNEL]: lazyPage(() =>
      import('./views/hilos/communications/channel.js').then(
        (module) => module.Channel,
      ),
    ),
    [HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION]: lazyPage(() =>
      import('./views/hilos/communications/configuration.js').then(
        (module) => module.Configuration,
      ),
    ),
    [HilosPages.COMMUNICATIONS_CHANNEL_FRAME]: lazyPage(() =>
      import('./views/hilos/communications/frame.js').then(
        (module) => module.Frame,
      ),
    ),
    [HilosPages.COMMUNICATIONS_DELIVERIES]: lazyPage(() =>
      import('./views/hilos/communications/deliveries.js').then(
        (module) => module.Deliveries,
      ),
    ),
    // The framework Appearance page: the framework owns the table, both windows
    // and the writes; the project binds its scope stores and action lifecycle
    // (views/hilos/appearance) and its theme catalog on the backend.
    [HilosPages.APPEARANCE]: lazyPage(() =>
      import('./views/hilos/appearance/appearance.js').then(
        (module) => module.Appearance,
      ),
    ),
    // The framework users/user admin pages: the framework owns the table, the
    // detail, and the rename round-trip; the project binds its scope stores,
    // connection, and typed user collection (views/hilos/users) and supplies its user
    // entity + presence sources on the backend.
    [HilosPages.USERS]: lazyPage(() =>
      import('./views/hilos/users/users.js').then((module) => module.Users),
    ),
    [HilosPages.USER]: lazyPage(() =>
      import('./views/hilos/users/user.js').then((module) => module.User),
    ),
    // The framework Analytics pages of one browser session and of one person: the
    // framework owns the header, the tables and every word; the project binds its
    // connection and page scope (views/hilos/analytics). The overview comes from
    // hilosAdminViews.
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
    // The framework OAuth admin pages (HIL-286): the framework owns the providers,
    // fields and return-address tables and their set / reset round-trips; the
    // project binds its connection, scope stores and action lifecycle
    // (views/hilos/security) and declares its provider directory on the backend.
    [HilosPages.SECURITY_OAUTH]: lazyPage(() =>
      import('./views/hilos/security/oauth.js').then(
        (module) => module.SecurityOauth,
      ),
    ),
    [HilosPages.SECURITY_OAUTH_PROVIDER]: lazyPage(() =>
      import('./views/hilos/security/oauth-provider.js').then(
        (module) => module.SecurityOauthProvider,
      ),
    ),
    // The framework sign-in methods page (HIL-427): the framework owns the table,
    // the live enabled set and the switch; the project binds its connection, scope
    // stores and action lifecycle (views/hilos/security) and declares its method
    // directory on the backend.
    [HilosPages.SECURITY_SIGN_IN_METHODS]: lazyPage(() =>
      import('./views/hilos/security/sign-in-methods.js').then(
        (module) => module.SecuritySignInMethods,
      ),
    ),
    [HilosPages.SECURITY_2FA]: lazyPage(() =>
      import('./views/hilos/security/two-factor.js').then(
        (module) => module.SecurityTwoFactor,
      ),
    ),
    [HilosPages.SECURITY_STEP_UP]: lazyPage(() =>
      import('./views/hilos/security/step-up.js').then(
        (module) => module.SecurityStepUp,
      ),
    ),
    [HilosPages.SECURITY_IMPERSONATION]: lazyPage(() =>
      import('./views/hilos/security/impersonation.js').then(
        (module) => module.SecurityImpersonation,
      ),
    ),
    [HilosPages.LEGAL]: lazyPage(() =>
      import('./views/hilos/legal/legal.js').then((module) => module.Legal),
    ),
    [HilosPages.LEGAL_DOCUMENT]: lazyPage(() =>
      import('./views/hilos/legal/legal-document.js').then(
        (module) => module.LegalDocument,
      ),
    ),
    [HilosPages.LEGAL_REVISION]: lazyPage(() =>
      import('./views/hilos/legal/legal-revision.js').then(
        (module) => module.LegalRevision,
      ),
    ),
    [HilosPages.LEGAL_ACCEPTANCES]: lazyPage(() =>
      import('./views/hilos/legal/legal-acceptances.js').then(
        (module) => module.LegalAcceptances,
      ),
    ),
    [HilosPages.LEGAL_SETTINGS]: lazyPage(() =>
      import('./views/hilos/legal/legal-settings.js').then(
        (module) => module.LegalSettings,
      ),
    ),
    [HilosPages.PROFILE]: Profile,
    [HilosPages.PROFILE_SIGN_IN]: ProfileSignIn,
    [HilosPages.PROFILE_SESSIONS]: ProfileSessions,
    [HilosPages.PROFILE_SECURITY]: ProfileSecurity,
    [HilosPages.PROFILE_AGREEMENTS]: ProfileAgreements,
    [HilosPages.PROFILE_AGREEMENTS_HISTORY]: ProfileAgreementsHistory,
    [HilosPages.PROFILE_DATA]: ProfileData,
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
    // The framework languages and countries section: the framework owns the two
    // lists, the two cards, their names, the language's locales and every
    // window over them; the project binds its connection, scope stores and
    // action lifecycle (views/hilos/i18n) and, on its backend, the pages, the
    // library agent and the browser tables. The section root stays the
    // framework default.
    [HilosPages.I18N_LANGUAGES]: lazyPage(() =>
      import('./views/hilos/i18n/lists/languages.js').then(
        (module) => module.I18nLanguages,
      ),
    ),
    [HilosPages.I18N_LANGUAGE]: lazyPage(() =>
      import('./views/hilos/i18n/details/language.js').then(
        (module) => module.I18nLanguage,
      ),
    ),
    [HilosPages.I18N_LANGUAGE_NAMES]: lazyPage(() =>
      import('./views/hilos/i18n/details/language-names.js').then(
        (module) => module.I18nLanguageNames,
      ),
    ),
    [HilosPages.I18N_LANGUAGE_LOCALES]: lazyPage(() =>
      import('./views/hilos/i18n/details/language-locales.js').then(
        (module) => module.I18nLanguageLocales,
      ),
    ),
    [HilosPages.I18N_COUNTRIES]: lazyPage(() =>
      import('./views/hilos/i18n/lists/countries.js').then(
        (module) => module.I18nCountries,
      ),
    ),
    [HilosPages.I18N_COUNTRY]: lazyPage(() =>
      import('./views/hilos/i18n/details/country.js').then(
        (module) => module.I18nCountry,
      ),
    ),
    [HilosPages.I18N_COUNTRY_NAMES]: lazyPage(() =>
      import('./views/hilos/i18n/details/country-names.js').then(
        (module) => module.I18nCountryNames,
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
    // Maintenance has no feature switch: the framework owns the circle and both
    // actions; the project binds its context and registers the page and table.
    [HilosPages.MAINTENANCE]: lazyPage(() =>
      import('./views/hilos/maintenance/maintenance.js').then(
        (module) => module.Maintenance,
      ),
    ),
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
