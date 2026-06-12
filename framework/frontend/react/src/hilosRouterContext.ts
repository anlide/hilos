// React context carrying the core navigator (HilosRouter) to the SDK's
// navigation components. A project provides it once near the app root
// (`<HilosRouterContext.Provider value={router}>`); HilosLink and HilosView
// read it.
import { createContext } from 'react'
import type { HilosRouter } from '@hilos/core'

/** Provides the application's {@link HilosRouter} to HilosLink and HilosView. */
export const HilosRouterContext = createContext<HilosRouter | null>(null)
