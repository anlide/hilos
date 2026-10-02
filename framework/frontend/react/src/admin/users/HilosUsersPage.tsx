// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic, the row view-model, and the
// frame the table declares — its columns, search, and empty words — are the core
// headless's (createHilosUsersTable / HilosUserRow); this view owns only the cell
// markup, so a project mounts it by passing its HilosUsersContext. The framework
// owns every cell except the trailing actions cell, which a project fills through
// the `rowActions` render prop (e.g. a link to the detail page); the takeover
// lives on the person's card since HIL-1170. The bar's "Past deadline on" filter
// narrows the list to the people past one legal document's deadline and lives
// in the address too (`/hilos/users/terms`, HIL-945): the legal section's root
// links its count there, and changing the filter rewrites the address.
// Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo } from 'react'
import type { ReactNode } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  USER_ONLINE_SESSION_COUNT_FIELD,
  USER_PRESENCE_FIELD,
  createHilosUsersTable,
} from '@hilos/core'
import type { HilosUserRow, HilosUsersContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'

/** Props for {@link HilosUsersPage}. */
export interface HilosUsersPageProps {
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
  /** The trailing actions cell for one row (e.g. an "Open" link). */
  rowActions?: (row: HilosUserRow) => ReactNode
}

/**
 * The framework users admin page: the searchable, sortable users table.
 *
 * @param props The project context and the optional trailing-actions renderer.
 */
export function HilosUsersPage({ context, rowActions }: HilosUsersPageProps) {
  // The lapsed filter is read from the address the page opened on and written
  // back as it changes; mounted without a navigator, the list opens whole and
  // leaves the address alone.
  const router = useContext(HilosRouterContext)
  const users = useMemo(
    () => createHilosUsersTable(context, router ?? undefined),
    [context, router],
  )

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    users.start()

    return () => users.dispose()
  }, [users])

  return (
    <HilosAdminPage page={HilosPages.USERS}>
      <HilosViewportTable
        controller={users.controller}
        cells={{
          id: (row) => row.id,
          name: (row) => <HilosHideable value={row.name} />,
          [USER_PRESENCE_FIELD]: (row) => (
            <span
              className={`badge ${
                row.presence === 'online'
                  ? 'text-bg-success'
                  : 'text-bg-secondary'
              }`}
            >
              {row.presence}
            </span>
          ),
          [USER_ONLINE_SESSION_COUNT_FIELD]: (row) => row.onlineSessionCount,
          lastActivity: (row) => row.lastActivity ?? '—',
          [HILOS_TABLE_ACTIONS_KEY]: (row) => rowActions?.(row),
        }}
      />
    </HilosAdminPage>
  )
}
