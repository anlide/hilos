// HilosView — the routed outlet (the React Router `<Outlet>` of the SDK). It
// mirrors the core navigator's current route and renders the component the
// project mapped to that page key, swapping it in place as navigation changes
// the route. An unmapped page renders nothing. The router must be in context.
import { useContext } from 'react'
import type { ComponentType } from 'react'

import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosView}: the page-key → component map. */
export interface HilosViewProps {
  /** Maps a page key to the component rendered while that page is current. */
  pages: Record<string, ComponentType>
}

/**
 * Render the component mapped to the navigator's current page.
 *
 * @param props The page-key → component map.
 */
export function HilosView({ pages }: HilosViewProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosView requires a HilosRouterContext provider.')
  }

  const route = useSignal(router.currentRoute)
  const View = pages[route.page]

  return View ? <View /> : null
}
