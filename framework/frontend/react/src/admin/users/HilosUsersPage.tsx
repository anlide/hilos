// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic, the row view-model, and the
// frame the table declares — its columns, search, and empty words — are the core
// headless's (createHilosUsersTable / HilosUserRow); this view owns only the cell
// markup, so a project mounts it by passing its HilosUsersContext. The framework
// owns every cell except the trailing actions cell, which a project fills through
// the `rowActions` render prop (e.g. a link to the detail page) — the framework's
// own row action, the takeover, is drawn ahead of it. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  USER_ONLINE_SESSION_COUNT_FIELD,
  USER_PRESENCE_FIELD,
  createHilosImpersonate,
  createHilosUsersTable,
} from '@hilos/core'
import type { HilosUserRow, HilosUsersContext } from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

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
  const users = useMemo(() => createHilosUsersTable(context), [context])

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    users.start()

    return () => users.dispose()
  }, [users])

  // The takeover: a confirm modal before an admin assumes a user's identity, as
  // every mutation on this project is confirmed in one. The button is on every row
  // but your own — taking yourself over is refused server-side, and a control whose
  // only outcome is a refusal is not one.
  const impersonate = useMemo(() => createHilosImpersonate(context), [context])
  const currentUid = useSignal(impersonate.currentUserId)
  const [impersonateOpen, setImpersonateOpen] = useState(false)
  const [impersonateRow, setImpersonateRow] = useState<HilosUserRow | null>(
    null,
  )
  const takeover = useTrackedAction()

  function openImpersonate(row: HilosUserRow): void {
    takeover.clearError()
    setImpersonateRow(row)
    setImpersonateOpen(true)
  }

  function closeImpersonate(): void {
    setImpersonateOpen(false)
  }

  // Authoritative-backend: the visible effect — the shell banner, and this admin
  // session becoming the non-admin target, which drops this admin-only page — is
  // server-driven through the handshake broadcast, so a success only closes the
  // confirm; a refusal keeps it open with the sentence the page sent back.
  async function submitImpersonate(): Promise<void> {
    if (!impersonateRow || takeover.busy) {
      return
    }
    if (await takeover.run(impersonate.start(impersonateRow.id))) {
      closeImpersonate()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.USERS}>
      <HilosViewportTable
        controller={users.controller}
        cells={{
          id: (row) => row.id,
          name: (row) => row.name,
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
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <>
              {row.id === currentUid ? null : (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  title="Impersonate"
                  aria-label="Impersonate"
                  data-id={`hilos-users-impersonate-${row.id}`}
                  onClick={() => openImpersonate(row)}
                >
                  <i className="bi bi-person-badge" aria-hidden="true" />
                </button>
              )}
              {rowActions?.(row)}
            </>
          ),
        }}
      />

      <HilosModal
        open={impersonateOpen}
        title={
          impersonateRow
            ? `Impersonate · ${impersonateRow.name}`
            : 'Impersonate user'
        }
        closeOnBackdrop={!takeover.busy}
        closeOnEsc={!takeover.busy}
        initialFocus="dialog"
        onClose={closeImpersonate}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={takeover.busy}
              data-id="hilos-users-impersonate-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={takeover.loading}
              disabled={takeover.busy}
              data-id="hilos-users-impersonate-confirm"
              onClick={() => void submitImpersonate()}
            >
              Impersonate
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={takeover} />
        {impersonateRow ? (
          <p className="mb-0">
            Become <strong>{impersonateRow.name}</strong> and see the app as
            they do? You can stop from the banner at any time.
          </p>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
