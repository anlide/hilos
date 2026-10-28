// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand prop and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the home page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator (an extra status
// surface allowed by docs/agents/frontend/core-and-connection.md).
import {
  HilosAvatar,
  HilosLayout,
  HilosNotificationBell,
  HilosView,
  useSignal,
} from '@hilos/react/base'
import { hilosAdminViews } from '@hilos/react/admin-views'
import {
  HilosPages,
  hilosSessionAvatarMark,
  lazyPage,
  type AuthGate,
  type PageView,
} from '@hilos/core/base'
import type { ComponentType } from 'react'

import AuthSurface from './auth/AuthSurface.js'
import { connection } from './bootstrap/connection.js'
import { currentUserName } from './bootstrap/session.js'
import { PAGE_MAIN } from './pages/keys.js'
import About from './views/About/About.js'
import License from './views/License/License.js'
import Main from './views/Main/Main.js'
import MainSkeleton from './views/Main/MainSkeleton.js'
import Privacy from './views/Privacy/Privacy.js'
import Terms from './views/Terms/Terms.js'

// The page-key → view map HilosView renders from. A page with no mapped view
// renders nothing; a page the backend does not register is refused by the
// server, and the outlet draws that refusal instead.
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
  [HilosPages.PROFILE_NOTIFICATIONS]: lazyPage(() =>
    import('./views/Hilos/ProfileNotifications/ProfileNotifications.js').then(
      (m) => m.default,
    ),
  ),
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
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

export default function App({ authGate }: AppProps) {
  const userName = useSignal(currentUserName)
  // The standing mark by the avatar (HIL-945): a takeover, or the session's own
  // scheduled deletion, in the color of the strip that says it in words.
  const avatarMark = useSignal(hilosSessionAvatarMark)

  return (
    <HilosLayout
      connection={connection}
      brand={
        <>
          <i className="bi bi-flower1" aria-hidden="true" />
          {/* The name folds down to the icon on a narrow screen and stays the
              link's accessible name there (mockups/framework/layout, "На узком экране"). */}
          <span className="d-none d-md-inline ms-1">Hilos Flowers</span>
          <span className="visually-hidden d-md-none">Hilos Flowers</span>
        </>
      }
      user={
        userName ? (
          // The avatar is not a link: the demo has no profile yet — it arrives
          // with the shop's "My orders". The bell sits in front of it.
          <>
            <HilosNotificationBell connection={connection} />
            <span className="small" data-id="nav-profile-name" title={userName}>
              <HilosAvatar name={userName} mark={avatarMark} />
              <span className="visually-hidden">{userName}</span>
            </span>
          </>
        ) : (
          // A visitor gets one button that opens the surface over the page they are
          // standing on (mockups/framework/layout, the "guest" tile): neither bell
          // nor gear. The gear a visitor sees on a node in the admin view mode is
          // the shell's own.
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
      <HilosView
        pages={pages}
        pageSkeletons={pageSkeletons}
        authSurface={AuthSurface}
        authGate={authGate}
      />
    </HilosLayout>
  )
}
