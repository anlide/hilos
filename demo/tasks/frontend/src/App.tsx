// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand prop and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator (an extra status
// surface allowed by docs/agents/frontend/core-and-connection.md).
import {
  HilosAvatar,
  HilosLayout,
  HilosMagicLinkPage,
  HilosSecondFactorCancelPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosRouterContext,
  HilosView,
  hilosAdminViews,
  useSignal,
} from '@hilos/react'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HilosPages,
  type AuthGate,
} from '@hilos/core'
import { useContext } from 'react'
import type { ComponentType } from 'react'

import AuthSurface from './auth/AuthSurface'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import { currentUserIsAdmin, currentUserName } from './bootstrap/session'
import { PAGE_MAIN } from './pages/keys'
import About from './views/About/About'
import HilosBackup from './views/Hilos/Backup/Backup'
import HilosLogsKeys from './views/Hilos/Logs/Keys'
import HilosLogsOverview from './views/Hilos/Logs/Overview'
import HilosLogsRotations from './views/Hilos/Logs/Rotations'
import HilosLogsSettings from './views/Hilos/Logs/Settings'
import HilosLogsView from './views/Hilos/Logs/View'
import HilosLogsWorkers from './views/Hilos/Logs/Workers'
import HilosMaintenance from './views/Hilos/Maintenance/Maintenance.js'
import HilosSecurityOauth from './views/Hilos/Security/SecurityOauth'
import HilosSecurityOauthProvider from './views/Hilos/Security/SecurityOauthProvider'
import HilosSecuritySignInMethods from './views/Hilos/Security/SecuritySignInMethods'
import HilosSecurityTwoFactor from './views/Hilos/Security/SecurityTwoFactor'
import ProfileSecurity from './views/Profile/ProfileSecurity'
import ProfileData from './views/Profile/ProfileData.js'
import HilosUser from './views/Hilos/Users/User'
import HilosUsers from './views/Hilos/Users/Users'
import License from './views/License/License'
import Main from './views/Main/Main'
import MainSkeleton from './views/Main/MainSkeleton'
import Privacy from './views/Privacy/Privacy'
import Settings from './views/Hilos/Settings/Settings'
import Terms from './views/Terms/Terms'

// The page-key → view map HilosView renders from. Pages without a mapped view
// (other routes land later) render nothing.
const pages: Record<string, ComponentType> = {
  [PAGE_MAIN]: Main,
  // The Hilos admin section. The framework ships a real default page for every
  // admin key (hilosAdminViews) — including the dashboard — so the demo maps only
  // the pages it implements itself; the rest render the framework default, never
  // recopied per project (page-module-structure.md).
  ...hilosAdminViews(),
  // The framework settings admin page, activated configure-only: the framework
  // owns the table and the add/update/delete lifecycle; the project binds only its
  // scope stores + action lifecycle (views/Hilos/Settings) and its catalog on the backend.
  [HilosPages.SETTINGS]: Settings,
  // The framework users/user admin pages: the framework owns the table, the
  // detail, and the rename round-trip; the project binds its scope stores,
  // connection, and typed user collection (views/Hilos/Users) and supplies its
  // user entity + presence sources on the backend.
  [HilosPages.USERS]: HilosUsers,
  [HilosPages.USER]: HilosUser,
  // The framework backup page, activated configure-only: the framework owns the
  // archive and verifier-circle tables, the create / delete / keep / reopen
  // round-trips and the monopoly agent; the project binds its context
  // (views/Hilos/Backup) and, on its backend, the catalog, the page and the table.
  [HilosPages.BACKUP]: HilosBackup,
  // Maintenance has no feature switch: the framework owns the circle and both
  // actions; the project binds its context and registers the page and table.
  [HilosPages.MAINTENANCE]: HilosMaintenance,
  // The framework logs section, activated whole: the framework owns the six
  // screens, their tables and every phrase on them; the project binds its
  // connection, scope stores and action lifecycle (views/Hilos/Logs) and, on its
  // backend, the pages, the three agents and the three browser tables.
  [HilosPages.LOGS]: HilosLogsOverview,
  [HilosPages.LOGS_KEYS]: HilosLogsKeys,
  [HilosPages.LOGS_WORKERS]: HilosLogsWorkers,
  [HilosPages.LOGS_ROTATIONS]: HilosLogsRotations,
  [HilosPages.LOGS_SETTINGS]: HilosLogsSettings,
  [HilosPages.LOGS_VIEW]: HilosLogsView,
  // The framework OAuth admin pages (HIL-286): the framework owns the providers,
  // fields and return-address tables and the set / reset round-trips; the project
  // binds its context (views/Hilos/Security) and, on its backend, declares its
  // provider directory.
  [HilosPages.SECURITY_OAUTH]: HilosSecurityOauth,
  [HilosPages.SECURITY_OAUTH_PROVIDER]: HilosSecurityOauthProvider,
  // The framework sign-in methods page (HIL-427): the framework owns the table,
  // the live enabled set and the switch; the project binds its context
  // (views/Hilos/Security) and, on its backend, declares its method directory.
  [HilosPages.SECURITY_SIGN_IN_METHODS]: HilosSecuritySignInMethods,
  [HilosPages.SECURITY_2FA]: HilosSecurityTwoFactor,
  [HilosPages.PROFILE_SECURITY]: ProfileSecurity,
  [HilosPages.PROFILE_DATA]: ProfileData,
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
  const isAdmin = useSignal(currentUserIsAdmin)
  const userName = useSignal(currentUserName)

  // The magic-link confirm route (HIL-283) and the OAuth callback route
  // (HIL-281). Neither carries a page of its own — the router falls both back to
  // the main subscription so their actions route — so App swaps the framework
  // relay view in for the routed outlet while the path matches, and the relay
  // navigates home once the session upgrades. The paths come from @hilos/core
  // (HIL-409): a mail client and a provider enter them, so both halves have to
  // agree on the strings.
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
      brand="Hilos Tasks"
      isAdmin={isAdmin}
      user={
        userName ? (
          <>
            <HilosNotificationBell connection={connection} />
            <span className="small" data-id="nav-profile-name" title={userName}>
              <HilosAvatar name={userName} />
              <span className="visually-hidden">{userName}</span>
            </span>
          </>
        ) : (
          // A visitor gets neither bell nor gear — there is nothing to show — and
          // one button that opens the surface over the page they are standing on
          // (mockups/framework/layout, the "guest" tile).
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
