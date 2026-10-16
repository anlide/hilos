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
  hilosAdminViews,
  hilosSignal,
} from '@hilos/angular'
import { HilosPages, hilosSessionAvatarMark } from '@hilos/core'

import { AuthSurface } from './auth/authSurface.js'
import { currentUserName } from './bootstrap/session.js'
import { connection } from './bootstrap/connection.js'
import { PAGE_MAIN } from './pages/keys.js'
import { About } from './views/about/about.js'
import { License } from './views/license/license.js'
import { I18nCountry } from './views/hilos/i18n/details/country.js'
import { I18nCountryNames } from './views/hilos/i18n/details/country-names.js'
import { I18nLanguage } from './views/hilos/i18n/details/language.js'
import { I18nLanguageLocales } from './views/hilos/i18n/details/language-locales.js'
import { I18nLanguageNames } from './views/hilos/i18n/details/language-names.js'
import { I18nCountries } from './views/hilos/i18n/lists/countries.js'
import { I18nLanguages } from './views/hilos/i18n/lists/languages.js'
import { LogKeys } from './views/hilos/logs/keys.js'
import { LogRotations } from './views/hilos/logs/rotations.js'
import { LogSettings } from './views/hilos/logs/settings.js'
import { LogViewer } from './views/hilos/logs/view.js'
import { LogWorkers } from './views/hilos/logs/workers.js'
import { LogsOverview } from './views/hilos/logs/overview.js'
import { Maintenance } from './views/hilos/maintenance/maintenance.js'
import { Settings } from './views/hilos/settings/settings.js'
import { Appearance } from './views/hilos/appearance/appearance.js'
import { AnalyticsBrowserSession } from './views/hilos/analytics/browser-session.js'
import { AnalyticsPerson } from './views/hilos/analytics/person.js'
import { ChangeLog } from './views/hilos/change-log/change-log.js'
import { ChangeLogTable } from './views/hilos/change-log/change-log-table.js'
import { ChangeLogTables } from './views/hilos/change-log/change-log-tables.js'
import { User } from './views/hilos/users/user.js'
import { Users } from './views/hilos/users/users.js'
import { Main } from './views/main/main.js'
import { MainSkeleton } from './views/main/main-skeleton.js'
import { Privacy } from './views/privacy/privacy.js'
import { Terms } from './views/terms/terms.js'

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
  protected readonly pages: Record<string, Type<unknown>> = {
    [PAGE_MAIN]: Main,
    // The framework supplies the admin defaults. This demo binds the sections
    // activated by its backend to their Angular contexts and leaves the rest to
    // the defaults.
    ...hilosAdminViews(),
    [HilosPages.SETTINGS]: Settings,
    [HilosPages.APPEARANCE]: Appearance,
    [HilosPages.USERS]: Users,
    [HilosPages.USER]: User,
    // The framework Analytics pages of one browser session and of one person: the
    // framework owns the header, the tables and every word; the project binds its
    // connection and page scope (views/hilos/analytics). The overview comes from
    // hilosAdminViews.
    [HilosPages.ANALYTICS_BROWSER_SESSION]: AnalyticsBrowserSession,
    [HilosPages.ANALYTICS_PERSON]: AnalyticsPerson,
    // The framework Change Log section: the framework owns the tiles, the feed,
    // the tables viewport, the history, the receipt modal and every word; the
    // project binds its connection, page scope and action lifecycle
    // (views/hilos/change-log).
    [HilosPages.CHANGE_LOG]: ChangeLog,
    [HilosPages.CHANGE_LOG_TABLES]: ChangeLogTables,
    [HilosPages.CHANGE_LOG_TABLE]: ChangeLogTable,
    [HilosPages.LOGS]: LogsOverview,
    [HilosPages.LOGS_KEYS]: LogKeys,
    [HilosPages.LOGS_WORKERS]: LogWorkers,
    [HilosPages.LOGS_ROTATIONS]: LogRotations,
    [HilosPages.LOGS_SETTINGS]: LogSettings,
    [HilosPages.LOGS_VIEW]: LogViewer,
    [HilosPages.I18N_LANGUAGES]: I18nLanguages,
    [HilosPages.I18N_LANGUAGE]: I18nLanguage,
    [HilosPages.I18N_LANGUAGE_NAMES]: I18nLanguageNames,
    [HilosPages.I18N_LANGUAGE_LOCALES]: I18nLanguageLocales,
    [HilosPages.I18N_COUNTRIES]: I18nCountries,
    [HilosPages.I18N_COUNTRY]: I18nCountry,
    [HilosPages.I18N_COUNTRY_NAMES]: I18nCountryNames,
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
