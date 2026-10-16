// HilosAnalyticsPage — the Analytics section overview. In Phase-1 it shows an
// empty state with a line and a link to the admin users list; overview content
// follows in HIL-1402. The core owns the shared words and the admin shell draws
// the page identity. Bootstrap classes only (styling-rules.md).
import { useContext } from 'react'
import {
  ANALYTICS_OVERVIEW_EMPTY_TEXT,
  ANALYTICS_OVERVIEW_OPEN_USERS_LABEL,
  HilosPages,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'

/** The Analytics section overview: the empty state and the way to the users list. */
export function HilosAnalyticsPage() {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosAnalyticsPage requires a provided Hilos router.')
  }

  const usersPath = router.resolvePath(HilosPages.USERS, {})

  return (
    <HilosAdminPage page={HilosPages.ANALYTICS}>
      <div
        className="border rounded p-4 text-center text-body-secondary"
        data-id="hilos-analytics-overview-empty"
      >
        <i className="bi bi-inbox fs-2 d-block mb-2" aria-hidden="true"></i>
        <p>{ANALYTICS_OVERVIEW_EMPTY_TEXT}</p>
        {usersPath !== undefined ? (
          <HilosLink
            to={usersPath}
            className="btn btn-sm btn-outline-secondary"
            data-id="hilos-analytics-overview-open-users"
          >
            {ANALYTICS_OVERVIEW_OPEN_USERS_LABEL}
          </HilosLink>
        ) : null}
      </div>
    </HilosAdminPage>
  )
}
