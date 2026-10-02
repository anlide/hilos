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
  hilosAdminViews,
  useSignal,
} from '@hilos/react'
import { HilosPages, hilosSessionAvatarMark, type AuthGate } from '@hilos/core'
import type { ComponentType } from 'react'

import AuthSurface from './auth/AuthSurface.js'
import { connection } from './bootstrap/connection.js'
import { currentUserName } from './bootstrap/session.js'
import { PAGE_MAIN } from './pages/keys.js'
import About from './views/About/About.js'
import HilosBackup from './views/Hilos/Backup/Backup.js'
import HilosMaintenance from './views/Hilos/Maintenance/Maintenance.js'
import Settings from './views/Hilos/Settings/Settings.js'
import HilosUser from './views/Hilos/Users/User.js'
import HilosUsers from './views/Hilos/Users/Users.js'
import License from './views/License/License.js'
import Main from './views/Main/Main.js'
import MainSkeleton from './views/Main/MainSkeleton.js'
import Privacy from './views/Privacy/Privacy.js'
import Terms from './views/Terms/Terms.js'

// The page-key → view map HilosView renders from. A page with no mapped view
// renders nothing; a page the backend does not register is refused by the
// server, and the outlet draws that refusal instead.
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
