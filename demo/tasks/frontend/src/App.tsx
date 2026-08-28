// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand prop and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator (an extra status
// surface allowed by docs/agents/frontend/core-and-connection.md).
import {
  HilosLayout,
  HilosMagicLinkPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosRouterContext,
  HilosView,
  hilosAdminViews,
  useSignal,
} from '@hilos/react'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  HilosPages,
  type AuthGate,
} from '@hilos/core'
import { useContext, useEffect, useRef, useState } from 'react'
import type { ComponentType } from 'react'

import AuthSurface from './auth/AuthSurface'
import { hilosAuthContext } from './auth/hilosAuthContext'
import { connection } from './bootstrap/connection'
import { currentUserIsAdmin, currentUserName } from './bootstrap/session'
import { PAGE_MAIN } from './pages/keys'
import About from './views/About/About'
import HilosUser from './views/Hilos/Users/User'
import HilosUsers from './views/Hilos/Users/Users'
import License from './views/License/License'
import Main from './views/Main/Main'
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
  [HilosPages.ABOUT]: About,
  [HilosPages.TERMS]: Terms,
  [HilosPages.PRIVACY]: Privacy,
  [HilosPages.LICENSE]: License,
}

// The framework sign-out action (HIL-710): signing out writes a session, so it is
// the sessions library that owns the command and this demo declares nothing for
// it. It is page-independent — the control lives in the shell — so it goes over
// the agent action rather than a page action.
const LOGOUT_ACTION = 'hilos_logout'

// Releases the sign-out button if the broadcast never arrives, so the control can
// never wedge on a dropped frame.
const LOGOUT_FALLBACK_MS = 5000

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

  // The clicker gets loading while sign-out is in flight: the button enters
  // `loggingOut` on send and leaves it when the broadcast lands — the session
  // downgrade drops `userName`, which both un-loads and (through its own
  // condition) removes the control, the visible confirmation.
  const [loggingOut, setLoggingOut] = useState(false)
  // The fallback timer's handle, kept so it is cleared the moment the broadcast
  // ends loading. Without clearing it, a stale timer from one sign-out could fire
  // during a later one and drop its loading early.
  const fallbackTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
    undefined,
  )
  // React to the broadcast: the downgrade clears the name, which ends loading and
  // cancels the now-unnecessary fallback timer.
  useEffect(() => {
    if (userName) {
      return
    }
    setLoggingOut(false)
    if (fallbackTimer.current !== undefined) {
      clearTimeout(fallbackTimer.current)
      fallbackTimer.current = undefined
    }
  }, [userName])

  const logout = (): void => {
    if (loggingOut) {
      return
    }
    setLoggingOut(true)
    if (!connection.sendAction(LOGOUT_ACTION, {})) {
      // Not sent (the socket is down): the action never left, so do not show
      // loading for a broadcast that will never come.
      setLoggingOut(false)

      return
    }
    fallbackTimer.current = setTimeout(() => {
      setLoggingOut(false)
    }, LOGOUT_FALLBACK_MS)
  }

  return (
    <HilosLayout
      connection={connection}
      brand="Hilos Tasks"
      isAdmin={isAdmin}
      user={
        userName ? (
          <>
            <HilosNotificationBell connection={connection} />
            <span className="small" data-id="nav-profile-name">
              <i className="bi bi-person-circle me-1" aria-hidden="true" />
              {userName}
            </span>
            <button
              type="button"
              className="btn btn-link nav-link d-inline-flex align-items-center p-0 ms-3"
              data-id="nav-logout"
              aria-label="Log out"
              disabled={loggingOut}
              onClick={logout}
            >
              {loggingOut ? (
                <span
                  className="spinner-border spinner-border-sm"
                  role="status"
                  aria-hidden="true"
                />
              ) : (
                <i className="bi bi-box-arrow-right" aria-hidden="true" />
              )}
            </button>
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
      ) : (
        <HilosView pages={pages} authSurface={AuthSurface} authGate={authGate} />
      )}
    </HilosLayout>
  )
}
