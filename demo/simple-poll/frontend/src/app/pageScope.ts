// The poll page subscription behavior: owns the page scope through the core
// PageSubscription manager and routes every page_response into it (dropping a
// late one for a page already left). Configuration — the routes and signal
// schemas — lives in pages.ts.
import {
  PageSubscription,
  SIGNAL_TYPE_PAGE_RESPONSE,
  type HilosConnection,
  type PageResponseWire,
} from '@hilos/core'

import { scopes } from './session'

/**
 * Create the page subscription manager and route every page_response into the
 * current page scope. Returns the manager so the entry point can subscribe the
 * page named by the URL.
 *
 * @param connection The application's Hilos connection.
 */
export function bindPageScope(connection: HilosConnection): PageSubscription {
  const pages = new PageSubscription(connection, scopes)
  connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_TYPE_PAGE_RESPONSE) {
      // Validated against pageResponseSchema at the parse boundary; this cast
      // is the declared typed selector for that schema's output.
      const response = signal.data as PageResponseWire
      pages.ingestPageResponse(response.page, response.payload)
    }
  })

  return pages
}
