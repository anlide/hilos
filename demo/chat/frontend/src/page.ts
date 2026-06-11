// The chat page subscription: the URL is the source of truth on cold load
// (wire-protocol.md). The main page is the chat itself and carries no tables;
// the two users-table pages (admin_users, hilos_users) answer the
// subscription with a page_response carrying the users-table snapshot in the
// scope-payload wire form. The PageSubscription manager owns the page scope
// and the late-signal guard; this module binds the project signal into it and
// exposes the typed selectors the views render from.
import {
  computedSignal,
  pageResponseSchema,
  PageSubscription,
  type EntityRef,
  type HilosConnection,
  type PageResponseWire,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from './session'

export const PAGE_MAIN = 'main'

export const PAGE_ADMIN_USERS = 'admin_users'

export const PAGE_HILOS_USERS = 'hilos_users'

const SIGNAL_PAGE_RESPONSE = 'page_response'

const USERS_TABLE = 'users'

const USER_SLOT = 'user'

/** Page key per cold-load route, mirroring the legacy route table. */
const PAGE_BY_PATH: Record<string, string> = {
  '/': PAGE_MAIN,
  '/hilos/admin_users': PAGE_ADMIN_USERS,
  '/hilos/users': PAGE_HILOS_USERS,
}

/** Concrete chat page signal schemas for the core parse boundary. */
export const pageSignalSchemas = {
  [SIGNAL_PAGE_RESPONSE]: pageResponseSchema,
}

/**
 * Resolve the page key the URL names on cold load; unknown paths fall back to
 * the main page like the legacy router's catch-all.
 *
 * @param pathname The location pathname at load time.
 */
export function pageForPath(pathname: string): string {
  return PAGE_BY_PATH[pathname] ?? PAGE_MAIN
}

/** One rendered users-table row: resolved, render-ready cells. */
export interface UsersTableRow {
  rowKey: string | number
  name: string
}

/**
 * The current page's users table, resolved for rendering: each row's `user`
 * entity reference is read back through the scope chain, so a row re-renders
 * when its entity changes. Empty until a page_response snapshot lands —
 * including on pages that have no users table at all.
 */
export const usersTableRows: ReadonlySignal<UsersTableRow[]> = computedSignal(
  () => {
    const rows = scopes.page()?.tables.signal(USERS_TABLE).get()?.rows ?? []

    return rows.map((row) => {
      const name = scopes
        .entitySignal(row.slots[USER_SLOT] as EntityRef)
        .get()?.fields.name

      return {
        rowKey: row.rowKey,
        name: typeof name === 'string' ? name : '',
      }
    })
  },
)

/** Ingest every page_response into the page scope via the manager. */
export function bindPageScope(connection: HilosConnection): PageSubscription {
  const pages = new PageSubscription(connection, scopes)
  connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_PAGE_RESPONSE) {
      // Validated against pageResponseSchema at the parse boundary; this cast
      // is the declared typed selector for that schema's output.
      const response = signal.data as PageResponseWire
      pages.ingestPageResponse(response.page, response.payload)
    }
  })

  return pages
}
