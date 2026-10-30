// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand prop and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator (an extra status
// surface allowed by docs/agents/frontend/core-and-connection.md).
import {
  HilosAvatar,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosSecondFactorCancelPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosRouterContext,
  HilosView,
  useSignal,
} from '@hilos/react/base'
import { hilosAdminViews } from '@hilos/react/admin-views'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  hilosSessionAvatarMark,
  lazyPage,
  type AuthGate,
  type PageView,
} from '@hilos/core/base'
import { useContext } from 'react'
import type { ComponentType } from 'react'

import AuthSurface from './auth/AuthSurface'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import { currentUserName, currentUserPhoto } from './bootstrap/session.js'
import { PAGE_MAIN } from './pages/keys'
import About from './views/About/About'
import Profile from './views/Profile/Profile.js'
import ProfileSignIn from './views/Profile/ProfileSignIn.js'
import ProfileSecurity from './views/Profile/ProfileSecurity'
import ProfileData from './views/Profile/ProfileData.js'
import License from './views/License/License'
import Main from './views/Main/Main'
import MainSkeleton from './views/Main/MainSkeleton'
import Privacy from './views/Privacy/Privacy'
import PrivacyHistory from './views/PrivacyHistory/PrivacyHistory.js'
import Terms from './views/Terms/Terms'
import TermsHistory from './views/TermsHistory/TermsHistory.js'

// The page-key → view map HilosView renders from. Pages without a mapped view
// (other routes land later) render nothing.
const pages: Record<string, PageView<ComponentType>> = {
  [PAGE_MAIN]: Main,
  // The Hilos admin section. The framework ships a real default page for every
  // admin key (hilosAdminViews) — including the dashboard — so the demo maps only
  // the pages it implements itself; the rest render the framework default, never
  // recopied per project (page-module-structure.md).
  ...hilosAdminViews(),
  // The framework Change Log section: the framework owns the feed, the tables
  // viewport, the history and the receipt reader; the project binds its
  // connection, page scope and action lifecycle (views/Hilos/ChangeLog).
  [HilosPages.CHANGE_LOG]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLog.js').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_FEED]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogFeed.js').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_NOISY]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogNoisy.js').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_TABLES]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTables.js').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_TABLE]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTable.js').then((m) => m.default),
  ),
  [HilosPages.CHANGE_LOG_TABLE_COLUMNS]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTableColumns.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.CHANGE_LOG_TABLE_TRIGGERS]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTableTriggers.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.CHANGE_LOG_TABLE_HISTORY]: lazyPage(() =>
    import('./views/Hilos/ChangeLog/ChangeLogTableHistory.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.I18N_LANGUAGES]: lazyPage(() =>
    import('./views/Hilos/I18n/Languages/Languages.js').then((m) => m.default),
  ),
  [HilosPages.I18N_LANGUAGE]: lazyPage(() =>
    import('./views/Hilos/I18n/Language/Language.js').then((m) => m.default),
  ),
  [HilosPages.I18N_LANGUAGE_NAMES]: lazyPage(() =>
    import('./views/Hilos/I18n/LanguageNames/LanguageNames.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.I18N_LANGUAGE_LOCALES]: lazyPage(() =>
    import('./views/Hilos/I18n/LanguageLocales/LanguageLocales.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.I18N_COUNTRIES]: lazyPage(() =>
    import('./views/Hilos/I18n/Countries/Countries.js').then((m) => m.default),
  ),
  [HilosPages.I18N_COUNTRY]: lazyPage(() =>
    import('./views/Hilos/I18n/Country/Country.js').then((m) => m.default),
  ),
  [HilosPages.I18N_COUNTRY_NAMES]: lazyPage(() =>
    import('./views/Hilos/I18n/CountryNames/CountryNames.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.DAEMON]: lazyPage(() =>
    import('./views/Hilos/Daemon/Daemon.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_PLACEMENT]: lazyPage(() =>
    import('./views/Hilos/Daemon/Placement.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_NODE]: lazyPage(() =>
    import('./views/Hilos/Daemon/Node.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_WORKERS]: lazyPage(() =>
    import('./views/Hilos/Daemon/Workers.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_CRON]: lazyPage(() =>
    import('./views/Hilos/Daemon/Cron.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_AGENTS]: lazyPage(() =>
    import('./views/Hilos/Daemon/Agents.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_WEBSOCKETS]: lazyPage(() =>
    import('./views/Hilos/Daemon/Websockets.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_HTTP_SERVER]: lazyPage(() =>
    import('./views/Hilos/Daemon/Http.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_ENV]: lazyPage(() =>
    import('./views/Hilos/Daemon/Env.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_ENV_ORPHANS]: lazyPage(() =>
    import('./views/Hilos/Daemon/EnvOrphans.js').then((m) => m.default),
  ),
  [HilosPages.DAEMON_ENV_MISMATCH]: lazyPage(() =>
    import('./views/Hilos/Daemon/EnvMismatch.js').then((m) => m.default),
  ),
  // The framework settings admin page, activated configure-only: the framework
  // owns the table and the add/update/delete lifecycle; the project binds only its
  // scope stores + action lifecycle (views/Hilos/Settings) and its catalog on the backend.
  [HilosPages.SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Settings/Settings.js').then((m) => m.default),
  ),
  // The framework Appearance admin page: the framework owns the table of the two
  // theme settings and both edit windows; the project binds only its connection,
  // scope stores + action lifecycle (views/Hilos/Appearance).
  [HilosPages.APPEARANCE]: lazyPage(() =>
    import('./views/Hilos/Appearance/Appearance.js').then((m) => m.default),
  ),
  // The framework users/user admin pages: the framework owns the table, the
  // detail, and the rename round-trip; the project binds its scope stores,
  // connection, and typed user collection (views/Hilos/Users) and supplies its
  // user entity + presence sources on the backend.
  [HilosPages.USERS]: lazyPage(() =>
    import('./views/Hilos/Users/Users.js').then((m) => m.default),
  ),
  [HilosPages.USER]: lazyPage(() =>
    import('./views/Hilos/Users/User.js').then((m) => m.default),
  ),
  // The framework Analytics pages: the framework owns the header, the tables and
  // every word; the project binds its connection and page scope
  // (views/Hilos/Analytics).
  [HilosPages.ANALYTICS]: lazyPage(() =>
    import('./views/Hilos/Analytics/Analytics.js').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_PEOPLE]: lazyPage(() =>
    import('./views/Hilos/Analytics/People.js').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_BROWSER_SESSION]: lazyPage(() =>
    import('./views/Hilos/Analytics/BrowserSession.js').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_PERSON]: lazyPage(() =>
    import('./views/Hilos/Analytics/Person.js').then((m) => m.default),
  ),
  // The framework backup page, activated configure-only: the framework owns the
  // archive and verifier-circle tables, the create / delete / keep / reopen
  // round-trips and the monopoly agent; the project binds its context
  // (views/Hilos/Backup) and, on its backend, the catalog, the page and the table.
  [HilosPages.BACKUP]: lazyPage(() =>
    import('./views/Hilos/Backup/Backup.js').then((m) => m.default),
  ),
  // Maintenance has no feature switch: the framework owns the circle and both
  // actions; the project binds its context and registers the page and table.
  [HilosPages.MAINTENANCE]: lazyPage(() =>
    import('./views/Hilos/Maintenance/Maintenance.js').then((m) => m.default),
  ),
  [HilosPages.COMMUNICATIONS]: lazyPage(() =>
    import('./views/Hilos/Communications/Communications.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.COMMUNICATIONS_CHANNEL]: lazyPage(() =>
    import('./views/Hilos/Communications/Channel.js').then((m) => m.default),
  ),
  [HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION]: lazyPage(() =>
    import('./views/Hilos/Communications/Configuration.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.COMMUNICATIONS_CHANNEL_FRAME]: lazyPage(() =>
    import('./views/Hilos/Communications/Frame.js').then((m) => m.default),
  ),
  [HilosPages.COMMUNICATIONS_DELIVERIES]: lazyPage(() =>
    import('./views/Hilos/Communications/Deliveries.js').then((m) => m.default),
  ),
  // The framework logs section, activated whole: the framework owns the six
  // screens, their tables and every phrase on them; the project binds its
  // connection, scope stores and action lifecycle (views/Hilos/Logs) and, on its
  // backend, the pages, the three agents and the three browser tables.
  [HilosPages.LOGS]: lazyPage(() =>
    import('./views/Hilos/Logs/Overview.js').then((m) => m.default),
  ),
  [HilosPages.LOGS_KEYS]: lazyPage(() =>
    import('./views/Hilos/Logs/Keys.js').then((m) => m.default),
  ),
  [HilosPages.LOGS_WORKERS]: lazyPage(() =>
    import('./views/Hilos/Logs/Workers.js').then((m) => m.default),
  ),
  [HilosPages.LOGS_ROTATIONS]: lazyPage(() =>
    import('./views/Hilos/Logs/Rotations.js').then((m) => m.default),
  ),
  [HilosPages.LOGS_SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Logs/Settings.js').then((m) => m.default),
  ),
  [HilosPages.LOGS_VIEW]: lazyPage(() =>
    import('./views/Hilos/Logs/View.js').then((m) => m.default),
  ),
  // The framework OAuth admin pages (HIL-286): the framework owns the providers,
  // fields and return-address tables and the set / reset round-trips; the project
  // binds its context (views/Hilos/Security) and, on its backend, declares its
  // provider directory.
  [HilosPages.SECURITY_OAUTH]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityOauth.js').then((m) => m.default),
  ),
  [HilosPages.SECURITY_OAUTH_PROVIDER]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityOauthProvider.js').then(
      (m) => m.default,
    ),
  ),
  // The framework sign-in methods page (HIL-427): the framework owns the table,
  // the live enabled set and the switch; the project binds its context
  // (views/Hilos/Security) and, on its backend, declares its method directory.
  [HilosPages.SECURITY_SIGN_IN_METHODS]: lazyPage(() =>
    import('./views/Hilos/Security/SecuritySignInMethods.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_2FA]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityTwoFactor.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.SECURITY_STEP_UP]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityStepUp.js').then((m) => m.default),
  ),
  [HilosPages.SECURITY_IMPERSONATION]: lazyPage(() =>
    import('./views/Hilos/Security/SecurityImpersonation.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.LEGAL]: lazyPage(() =>
    import('./views/Hilos/Legal/Legal.js').then((m) => m.default),
  ),
  [HilosPages.LEGAL_DOCUMENT]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalDocument.js').then((m) => m.default),
  ),
  [HilosPages.LEGAL_REVISION]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalRevision.js').then((m) => m.default),
  ),
  [HilosPages.LEGAL_ACCEPTANCES]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalAcceptances.js').then((m) => m.default),
  ),
  [HilosPages.LEGAL_SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Legal/LegalSettings.js').then((m) => m.default),
  ),
  [HilosPages.PROFILE]: Profile,
  [HilosPages.PROFILE_SIGN_IN]: ProfileSignIn,
  [HilosPages.PROFILE_SECURITY]: ProfileSecurity,
  [HilosPages.PROFILE_DATA]: ProfileData,
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.TERMS_HISTORY]: TermsHistory,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.PRIVACY_HISTORY]: PrivacyHistory,
  [HilosPages.LICENSE]: License,
}

// The pages that draw a skeleton of their own shape while they wait for their
// first answer (HIL-983); every other page gets the outlet's default skeleton.
const pageSkeletons: Record<string, ComponentType> = {
  [PAGE_MAIN]: MainSkeleton,
}

export interface AppProps {
  /**
   * The application's auth gate. Passed as well as provided: HilosView needs it
   * to open the sign-in modal over a live page, and the shell's Sign in button
   * calls it directly.
   */
  authGate: AuthGate
}

/** The profile root the avatar leads to (HIL-1169). */
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE]

export default function App({ authGate }: AppProps) {
  const userName = useSignal(currentUserName)
  const userPhoto = useSignal(currentUserPhoto)
  // The standing mark by the avatar (HIL-945): a takeover, or the session's own
  // scheduled deletion, in the color of the strip that says it in words.
  const avatarMark = useSignal(hilosSessionAvatarMark)

  // The magic-link confirm route (HIL-283) and the OAuth callback route
  // (HIL-281). Neither carries a page of its own — the address stays a route
  // miss and nothing is subscribed — so App swaps the framework relay view in
  // for the routed outlet while the path matches. The session answer opens the
  // gate the relay dispatches through, and the relay navigates home once the
  // session upgrades. The paths come from @hilos/core (HIL-409): a mail client
  // and a provider enter them, so both halves have to agree on the strings.
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'App requires a HilosRouterContext provider: <HilosRouterContext.Provider value={router}>.',
    )
  }
  const currentPath = useSignal(router.currentPath)

  return (
    <HilosLayout
      connection={connection}
      brand={
        <>
          <i className="bi bi-check2-square" aria-hidden="true" />
          <span className="d-none d-md-inline ms-1">Hilos Tasks</span>
          <span className="visually-hidden d-md-none">Hilos Tasks</span>
        </>
      }
      user={
        userName ? (
          <>
            <HilosNotificationBell connection={connection} />
            <HilosLink
              to={profileHref}
              className="nav-link d-inline-flex align-items-center p-0"
              data-id="nav-profile-name"
              title={userName}
            >
              <HilosAvatar
                name={userName}
                mark={avatarMark}
                photo={userPhoto}
              />
              <span className="visually-hidden">{userName}</span>
            </HilosLink>
          </>
        ) : (
          // A visitor gets no bell — there is nothing to show — and one button that
          // opens the surface over the page they are standing on
          // (mockups/framework/layout, the "guest" tile). The gear a visitor sees
          // on a node in the admin view mode is the shell's own, not this slot's.
          <button
            type="button"
            className="btn btn-sm btn-primary"
            data-id="nav-signin"
            onClick={() => authGate.requireAuth()}
          >
            Sign in
          </button>
        )
      }
    >
      {currentPath === AUTH_MAGIC_LINK_PATH ? (
        <HilosMagicLinkPage context={hilosAuthContext} />
      ) : currentPath === AUTH_OAUTH_CALLBACK_PATH ? (
        <HilosOAuthCallbackPage context={hilosAuthContext} />
      ) : currentPath === AUTH_SECOND_FACTOR_CANCEL_PATH ? (
        <HilosSecondFactorCancelPage context={hilosAuthContext} />
      ) : (
        <HilosView
          pages={pages}
          pageSkeletons={pageSkeletons}
          authSurface={AuthSurface}
          authGate={authGate}
        />
      )}
    </HilosLayout>
  )
}
