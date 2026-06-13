// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand prop and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator (an extra status
// surface allowed by docs/agents/frontend/core-and-connection.md).
import { HilosLayout, HilosView } from '@hilos/react'
import { HilosPages } from '@hilos/core'
import type { ComponentType } from 'react'

import { connection } from './connection'
import { PAGE_MAIN } from './pageKeys'
import Dashboard from './views/Dashboard/Dashboard'
import Main from './views/Main/Main'

// The page-key → view map HilosView renders from. Pages without a mapped view
// (other routes land later) render nothing.
const pages: Record<string, ComponentType> = {
  [PAGE_MAIN]: Main,
  [HilosPages.DASHBOARD]: Dashboard,
}

export default function App() {
  return (
    <HilosLayout connection={connection} brand="Hilos Todo">
      <HilosView pages={pages} />
    </HilosLayout>
  )
}
