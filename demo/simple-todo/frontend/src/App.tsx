// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand prop and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator (an extra status
// surface allowed by docs/agents/frontend/core-and-connection.md).
import { HilosDashboardPage, HilosLayout, HilosView } from '@hilos/react'
import { HilosPages } from '@hilos/core'
import type { ComponentType } from 'react'

import { connection } from './bootstrap/connection'
import { PAGE_MAIN } from './pages/keys'
import About from './views/About/About'
import HilosUser from './views/Hilos/Users/User'
import HilosUsers from './views/Hilos/Users/Users'
import License from './views/License/License'
import Main from './views/Main/Main'
import Privacy from './views/Privacy/Privacy'
import Settings from './views/Settings/Settings'
import Terms from './views/Terms/Terms'

// The page-key → view map HilosView renders from. Pages without a mapped view
// (other routes land later) render nothing.
const pages: Record<string, ComponentType> = {
  [PAGE_MAIN]: Main,
  // The framework dashboard is rendered straight from the SDK — this demo adds no
  // dashboard sections of its own, so it gets the framework sections as-is (a
  // project that does would wrap HilosDashboardPage and pass its cards as children).
  [HilosPages.DASHBOARD]: HilosDashboardPage,
  // The framework settings admin page, activated configure-only: the framework
  // owns the table and the add/update/delete lifecycle; the project binds only its
  // scope stores + action lifecycle (views/Settings) and its catalog on the backend.
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

export default function App() {
  return (
    <HilosLayout connection={connection} brand="Hilos Todo">
      <HilosView pages={pages} />
    </HilosLayout>
  )
}
