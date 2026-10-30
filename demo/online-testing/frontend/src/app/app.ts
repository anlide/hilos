// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand slot and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator.
import { ChangeDetectionStrategy, Component, inject } from '@angular/core'
import type { Type } from '@angular/core'
import {
  HILOS_AUTH_GATE,
  HilosAvatar,
  HilosLayout,
  HilosNotificationBell,
  HilosView,
  hilosSignal,
} from '@hilos/angular/base'
import { hilosAdminViews } from '@hilos/angular/admin-views'
import {
  HilosPages,
  lazyPage,
  hilosSessionAvatarMark,
  type PageView,
} from '@hilos/core/base'

import { AuthSurface } from './auth/authSurface.js'
import { currentUserName } from './bootstrap/session.js'
import { connection } from './bootstrap/connection.js'
import { PAGE_MAIN } from './pages/keys.js'
import { About } from './views/about/about.js'
import { License } from './views/license/license.js'
import { LicenseDependencies } from './views/license-dependencies/license-dependencies.js'
import { Main } from './views/main/main.js'
import { MainSkeleton } from './views/main/main-skeleton.js'
import { Privacy } from './views/privacy/privacy.js'
import { PrivacyHistory } from './views/privacy-history/privacy-history.js'
import { Terms } from './views/terms/terms.js'
import { TermsHistory } from './views/terms-history/terms-history.js'

@Component({
  selector: 'app-root',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAvatar, HilosLayout, HilosNotificationBell, HilosView],
  // The user region is ONE projected node, not two: the shell's slot is
  // `<ng-content select="[user]">`, which matches the root nodes of what is
  // projected, and a conditional block sitting on that boundary is the known
  // Angular trap. ngProjectAs names the slot explicitly and the branches live
  // safely inside it.
  template: `<hilos-layout [connection]="connection">
    <span brand>
      <i class="bi bi-mortarboard" aria-hidden="true"></i>
      <!-- The name folds down to the icon on a narrow screen and stays the
      link's accessible name there (mockups/framework/layout, "На узком экране"). -->
      <span class="d-none d-md-inline ms-1">Hilos Testing</span>
      <span class="visually-hidden d-md-none">Hilos Testing</span>
    </span>
    <ng-container ngProjectAs="[user]">
      @if (userName()) {
        <hilos-notification-bell [connection]="connection" />
        <!-- The avatar is not a link: the demo has no profile of its own, and
        signing out is the shell's own button. -->
        <span class="small" data-id="nav-profile-name" [title]="userName()">
          <hilos-avatar [name]="userName()" [mark]="avatarMark()" />
          <span class="visually-hidden">{{ userName() }}</span>
        </span>
      } @else {
        <!-- A visitor gets one button that opens the surface over the page
        they are standing on (mockups/framework/layout, the "guest" tile). The
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
    <hilos-view
      [pages]="pages"
      [pageSkeletons]="pageSkeletons"
      [authSurface]="authSurfaceType"
      [authGate]="authGate"
    />
  </hilos-layout>`,
})
export class App {
  protected readonly connection = connection

  protected readonly userName = hilosSignal(currentUserName)

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

  // The class, not an instance: HilosView mounts it through ngComponentOutlet.
  protected readonly authSurfaceType: Type<unknown> = AuthSurface

  // The page-key → view map HilosView renders from. A page with no mapped view
  // renders nothing; a page the backend does not register is refused by the
  // server, and the outlet draws that refusal instead.
  protected readonly pages: Record<string, PageView<Type<unknown>>> = {
    [PAGE_MAIN]: Main,
    // The framework supplies the admin defaults. This demo binds the sections
    // activated by its backend to their Angular contexts and leaves the rest to
    // the defaults.
    ...hilosAdminViews(),
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
    // The framework Analytics overview, browser session and person pages: the
    // framework owns the header, the tiles, the tables and every word; the project
    // binds its connection and page scope (views/hilos/analytics).
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
    [HilosPages.MAINTENANCE]: lazyPage(() =>
      import('./views/hilos/maintenance/maintenance.js').then(
        (module) => module.Maintenance,
      ),
    ),
    [HilosPages.ABOUT]: About,
    [HilosPages.TERMS]: Terms,
    [HilosPages.TERMS_HISTORY]: TermsHistory,
    [HilosPages.PRIVACY]: Privacy,
    [HilosPages.PRIVACY_HISTORY]: PrivacyHistory,
    [HilosPages.LICENSE]: License,
    [HilosPages.LICENSE_DEPENDENCIES]: LicenseDependencies,
  }

  // The pages that draw a skeleton of their own shape while they wait for their
  // first answer (HIL-983); every other page gets the outlet's default skeleton.
  protected readonly pageSkeletons: Record<string, Type<unknown>> = {
    [PAGE_MAIN]: MainSkeleton,
  }
}
