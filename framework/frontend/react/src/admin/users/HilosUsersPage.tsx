// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic and the row view-model are
// the core headless's (createHilosUsersTable / HilosUserRow); this view owns only
// the column set and the cell markup, so a project mounts it by passing its
// HilosUsersContext. The framework owns every cell except the trailing actions
// cell, which a project fills through the `rowActions` render prop (e.g. a link to
// the detail page). Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo } from 'react'
import type { ReactNode } from 'react'
import {
  HilosPages,
  USER_ONLINE_SESSION_COUNT_FIELD,
  USER_PRESENCE_FIELD,
  createHilosUsersTable,
} from '@hilos/core'
import type {
  HilosTableColumnOf,
  HilosUserRow,
  HilosUsersContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Props for {@link HilosUsersPage}. */
export interface HilosUsersPageProps {
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
  /** The trailing actions cell for one row (e.g. an "Open" link). */
  rowActions?: (row: HilosUserRow) => ReactNode
}

const COLUMNS: HilosTableColumnOf<HilosUserRow>[] = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: USER_PRESENCE_FIELD, label: 'Presence', sortable: true },
  {
    key: USER_ONLINE_SESSION_COUNT_FIELD,
    label: 'Sessions',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'lastActivity', label: 'Last activity', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/**
 * The framework users admin page: the searchable, sortable users table.
 *
 * @param props The project context and the optional trailing-actions renderer.
 */
export function HilosUsersPage({ context, rowActions }: HilosUsersPageProps) {
  const users = useMemo(() => createHilosUsersTable(context), [context])

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    users.start()

    return () => users.dispose()
  }, [users])

  return (
    <HilosAdminPage page={HilosPages.USERS}>
      <HilosViewportTable
        label="Users"
        controller={users.controller}
        columns={COLUMNS}
        searchable
        searchPlaceholder="Search users…"
        emptyText="No users yet."
        row={(row) => (
          <>
            <td className="text-body-secondary">{row.id}</td>
            <td className="fw-medium">{row.name}</td>
            <td>
              <span
                className={`badge ${
                  row.presence === 'online'
                    ? 'text-bg-success'
                    : 'text-bg-secondary'
                }`}
              >
                {row.presence}
              </span>
            </td>
            <td className="text-end">{row.onlineSessionCount}</td>
            <td>{row.lastActivity ?? '—'}</td>
            <td className="text-end">{rowActions?.(row)}</td>
          </>
        )}
      />
    </HilosAdminPage>
  )
}
