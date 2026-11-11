// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand prop and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the home page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator (an extra status
// surface allowed by docs/agents/frontend/core-and-connection.md).
import {
  HilosAvatar,
  HilosLayout,
  HilosLink,
  HilosMagicLinkPage,
  HilosNotificationBell,
  HilosOAuthCallbackPage,
  HilosRouterContext,
  HilosSecondFactorCancelPage,
  HilosView,
  useSignal,
} from '@hilos/react/base'
import { hilosAdminViews } from '@hilos/react/admin-views'
import {
  AUTH_MAGIC_LINK_PATH,
  AUTH_OAUTH_CALLBACK_PATH,
  AUTH_SECOND_FACTOR_CANCEL_PATH,
  HILOS_PAGE_ROUTES,
  HilosPages,
  hilosSessionAvatarMark,
  lazyPage,
  type AuthGate,
  type PageView,
} from '@hilos/core/base'
import { useContext } from 'react'
import type { ComponentType } from 'react'

import AuthSurface from './auth/AuthSurface.js'
import { hilosAuthContext } from './auth/hilosAuthContext.js'
import { connection } from './bootstrap/connection.js'
import { currentUserName, currentUserPhoto } from './bootstrap/session.js'
import { PAGE_MAIN } from './pages/keys.js'
import About from './views/About/About.js'
import License from './views/License/License.js'
import LicenseDependencies from './views/LicenseDependencies/LicenseDependencies.js'
import Main from './views/Main/Main.js'
import MainSkeleton from './views/Main/MainSkeleton.js'
import Privacy from './views/Privacy/Privacy.js'
import PrivacyHistory from './views/PrivacyHistory/PrivacyHistory.js'
import Profile from './views/Profile/Profile.js'
import ProfileData from './views/Profile/ProfileData.js'
import ProfileSecurity from './views/Profile/ProfileSecurity.js'
import ProfileSignIn from './views/Profile/ProfileSignIn.js'
import Terms from './views/Terms/Terms.js'
import TermsHistory from './views/TermsHistory/TermsHistory.js'

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
  // The framework settings admin page, activated configure-only: the framework
  // owns the table and the add/update/delete lifecycle; the project binds only its
  // scope stores + action lifecycle (views/Hilos/Settings) and its catalog on the backend.
  [HilosPages.SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Settings/Settings.js').then((m) => m.default),
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
  [HilosPages.ANALYTICS_BROWSER_SESSIONS]: lazyPage(() =>
    import('./views/Hilos/Analytics/BrowserSessions.js').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_BROWSER_SESSION]: lazyPage(() =>
    import('./views/Hilos/Analytics/BrowserSession.js').then((m) => m.default),
  ),
  [HilosPages.ANALYTICS_PERSON]: lazyPage(() =>
    import('./views/Hilos/Analytics/Person.js').then((m) => m.default),
  ),
  // The notification delivery journal of a channel: the one Communications page
  // the backend serves, because the notification delivery feature refuses to
  // start without it. The Communications hub, its channel page, configuration
  // and frame are not served here, so they are not mounted either.
  [HilosPages.COMMUNICATIONS_DELIVERIES]: lazyPage(() =>
    import('./views/Hilos/Communications/Deliveries.js').then((m) => m.default),
  ),
  // The person's own profile pages: the framework owns every section, the project
  // binds its connection, scope stores and action lifecycle (views/Profile). The
  // sessions, devices, agreements and notifications pages are not served here,
  // so they are not mounted either.
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
  [HilosPages.LICENSE_DEPENDENCIES]: LicenseDependencies,
}

// The pages that draw a skeleton of their own shape while they wait for their
// first answer (HIL-983); every other page gets the outlet's default skeleton.
const pageSkeletons: Record<string, ComponentType> = {
  [PAGE_MAIN]: MainSkeleton,
}

export interface AppProps {
  /**
   * The application's auth gate. HilosView needs it to open the sign-in modal
   * over a live page that answers that it wants an account.
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

  // The magic-link confirm route (HIL-283), the OAuth callback route (HIL-281)
  // and the second-factor cancel route. None of them carries a page of its own —
  // the address stays a route miss and nothing is subscribed — so App swaps the
  // framework relay view in for the routed outlet while the path matches. The
  // paths come from @hilos/core (HIL-409): a mail client and a provider enter
  // them, so both halves have to agree on the strings.
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
          <i className="bi bi-sun-fill" aria-hidden="true" />
          {/* The name folds down to the icon on a narrow screen and stays the
              link's accessible name there. */}
          <span className="d-none d-md-inline ms-1">Hilos Solar System</span>
          <span className="visually-hidden d-md-none">Hilos Solar System</span>
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
        ) : // A visitor gets neither a bell nor a Sign in button: the public site
        // asks nobody to sign in, and the sign-in surface opens where a page
        // wants an account. The gear a visitor sees on a node in the admin view
        // mode is the shell's own, not this slot's.
        null
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
