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
  HilosPageTemplate,
  HilosLink,
  HilosNotificationBell,
  HilosView,
  hilosSignal,
} from '@hilos/angular/base'
import { hilosAdminViews } from '@hilos/angular/admin-views'
import {
  HILOS_PAGE_ROUTES,
  HilosPages,
  lazyPage,
  hilosSessionAvatarMark,
  type PageView,
} from '@hilos/core/base'

import { AuthSurface } from './auth/authSurface.js'
import { currentUserId, currentUserName } from './bootstrap/session.js'
import { connection } from './bootstrap/connection.js'
import { PAGE_MAIN } from './pages/keys.js'
import { About } from './views/about/about.js'
import { License } from './views/license/license.js'
import { LicenseDependencies } from './views/license-dependencies/license-dependencies.js'
import { Main } from './views/main/main.js'
import { Privacy } from './views/privacy/privacy.js'
import { PrivacyHistory } from './views/privacy-history/privacy-history.js'
import { Profile } from './views/profile/profile.js'
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
    HilosNotificationBell,
    HilosView,
  ],
  // The user region is ONE projected node, not two: the shell's slot is
  // `<ng-content select="[user]">`, which matches the root nodes of what is
  // projected, and a conditional block sitting on that boundary is the known
  // Angular trap. ngProjectAs names the slot explicitly and the branches live
  // safely inside it.
  template: `<hilos-layout [connection]="connection">
    <span brand>
      <i class="bi bi-suit-spade-fill" aria-hidden="true"></i>
      <!-- The name folds down to the icon on a narrow screen and stays the
      link's accessible name there. -->
      <span class="d-none d-md-inline ms-1">Hilos Preferans</span>
      <span class="visually-hidden d-md-none">Hilos Preferans</span>
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
          <hilos-avatar [name]="userName()" [mark]="avatarMark()" />
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
      <hilos-view
        [pages]="pages"
        [authSurface]="authSurfaceType"
        [authGate]="authGate"
      />
    </ng-template>
  </hilos-layout>`,
})
export class App {
  protected readonly connection = connection

  protected readonly userName = hilosSignal(currentUserName)

  protected readonly userId = hilosSignal(currentUserId)

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
    [HilosPages.I18N]: lazyPage(() =>
      import('./views/hilos/i18n/overview.js').then(
        (module) => module.I18nOverview,
      ),
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
    [HilosPages.ABOUT]: About,
    [HilosPages.PROFILE]: Profile,
    [HilosPages.TERMS]: Terms,
    [HilosPages.TERMS_HISTORY]: TermsHistory,
    [HilosPages.PRIVACY]: Privacy,
    [HilosPages.PRIVACY_HISTORY]: PrivacyHistory,
    [HilosPages.LICENSE]: License,
    [HilosPages.LICENSE_DEPENDENCIES]: LicenseDependencies,
  }
}
