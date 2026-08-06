// HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
// stored-backup list inside the admin shell, with its row actions. The list is
// live — rows arrive over the socket from the backup runtime index plus the
// single in-progress backup, so an in-progress row shows an indeterminate
// progress bar until it completes and merges into the index. Its actions (create
// with a scope picker, per-row delete, per-row keep toggle) are the core
// headless's (createHilosBackupsActions); each dispatches a tracked action and
// surfaces the backend's failure (authoritative-backend). All table logic and the
// row view-model are the core headless's too; this view owns only the markup, so a
// project mounts it by passing its HilosBackupsContext. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_BACKUP_SCOPES,
  HilosPages,
  BACKUP_CREATED_AT_FIELD,
  BACKUP_ENV_FIELD,
  BACKUP_SCOPE_FIELD,
  BACKUP_SIZE_BYTES_FIELD,
  BACKUP_CHECKSUM_STATE_FIELD,
  BACKUP_DURATION_SECONDS_FIELD,
  BACKUP_STATUS_FIELD,
  BACKUP_KEEP_FIELD,
  createHilosBackupsActions,
  createHilosBackupsTable,
  formatBackupChecksum,
  formatBackupDuration,
  formatBackupSize,
  hasBackupFailureDetail,
  isBackupChecksumMismatch,
  isBackupDeletable,
  isBackupInProgress,
  isBackupKeepable,
} from '@hilos/core'
import type {
  HilosBackupRow,
  HilosBackupsContext,
  HilosTableColumnOf,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosBackupPage}. */
export interface HilosBackupPageProps {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosBackupsContext
}

const COLUMNS: HilosTableColumnOf<HilosBackupRow>[] = [
  { key: BACKUP_CREATED_AT_FIELD, label: 'Date', sortable: true },
  { key: BACKUP_ENV_FIELD, label: 'Environment', sortable: true },
  { key: BACKUP_SCOPE_FIELD, label: 'Scope', sortable: true },
  {
    key: BACKUP_SIZE_BYTES_FIELD,
    label: 'Size',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: BACKUP_CHECKSUM_STATE_FIELD, label: 'Checksum' },
  {
    key: BACKUP_DURATION_SECONDS_FIELD,
    label: 'Duration',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: BACKUP_STATUS_FIELD, label: 'Status', sortable: true },
  { key: BACKUP_KEEP_FIELD, label: 'Keep', headerClass: 'text-center' },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/** The backup status cell: a live progress bar, a success badge, or a failure badge. */
function statusCell(row: HilosBackupRow) {
  if (isBackupInProgress(row)) {
    return (
      <div className="progress" role="status" style={{ minWidth: '10rem' }}>
        <div
          className="progress-bar progress-bar-striped progress-bar-animated"
          style={{ width: '100%' }}
        >
          In progress
        </div>
      </div>
    )
  }
  if (row.finished === true) {
    return (
      <span className="badge text-bg-success">{row.status || 'success'}</span>
    )
  }

  return <span className="badge text-bg-danger">{row.status || 'error'}</span>
}

/**
 * The framework backup admin page: the searchable, sortable backup list with a
 * create toolbar and per-row keep / delete actions.
 *
 * @param props The project context (scope stores + action lifecycle).
 */
export function HilosBackupPage({ context }: HilosBackupPageProps) {
  const backups = useMemo(() => createHilosBackupsTable(context), [context])
  const actions = useMemo(() => createHilosBackupsActions(context), [context])

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    backups.start()

    return () => backups.dispose()
  }, [backups])

  // Create toolbar: pick a scope and start a backup as a tracked action.
  const [createScope, setCreateScope] = useState(HILOS_BACKUP_SCOPES[0].value)
  const create = useTrackedAction()

  async function submitCreate(): Promise<void> {
    if (create.busy) {
      return
    }
    await create.run(actions.sendBackupCreate(createScope))
  }

  // Keep toggle: a per-row switch dispatched as a tracked action; the row stays
  // authoritative (the switch reflects the live row's keep, never an optimistic flip).
  const keep = useTrackedAction()
  const [keepPendingId, setKeepPendingId] = useState<string | null>(null)

  async function toggleKeep(row: HilosBackupRow): Promise<void> {
    if (keep.busy) {
      return
    }
    setKeepPendingId(row.id)
    await keep.run(actions.sendBackupSetKeep(row.id, !row.keep))
    setKeepPendingId(null)
  }

  // Delete dialog: a completed backup only (never the in-progress row).
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteRow, setDeleteRow] = useState<HilosBackupRow | null>(null)
  const del = useTrackedAction()

  function openDelete(row: HilosBackupRow): void {
    del.clearError()
    setDeleteRow(row)
    setDeleteOpen(true)
  }

  function closeDelete(): void {
    setDeleteOpen(false)
  }

  async function submitDelete(): Promise<void> {
    if (!deleteRow || del.busy) {
      return
    }
    if (await del.run(actions.sendBackupDelete(deleteRow.id))) {
      closeDelete()
    }
  }

  // Failure-detail dialog: a read-only view of a failed backup's stored reason. It
  // holds a snapshot of the row it opened on, so a parallel delete or rotation does
  // not close it; the text is already in the row, so there is no server request.
  const [detailsOpen, setDetailsOpen] = useState(false)
  const [detailsRow, setDetailsRow] = useState<HilosBackupRow | null>(null)

  function openDetails(row: HilosBackupRow): void {
    setDetailsRow(row)
    setDetailsOpen(true)
  }

  function closeDetails(): void {
    setDetailsOpen(false)
  }

  return (
    <HilosAdminPage page={HilosPages.BACKUP}>
      <div className="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
          <label className="form-label" htmlFor="hilos-backup-create-scope">
            Scope
          </label>
          <select
            id="hilos-backup-create-scope"
            className="form-select"
            disabled={create.busy}
            data-id="hilos-backup-create-scope"
            value={createScope}
            onChange={(event) => setCreateScope(event.target.value)}
          >
            {HILOS_BACKUP_SCOPES.map((scope) => (
              <option key={scope.value} value={scope.value}>
                {scope.label}
              </option>
            ))}
          </select>
        </div>
        <LoadingButton
          className="btn-primary"
          loading={create.loading}
          disabled={create.busy}
          data-id="hilos-backup-create"
          onClick={() => void submitCreate()}
        >
          Create backup
        </LoadingButton>
      </div>

      <HilosViewportTable
        label="Backups"
        controller={backups.controller}
        columns={COLUMNS}
        searchable
        searchPlaceholder="Search backups…"
        emptyText="No backups yet."
        row={(row) => (
          <>
            <td className="text-nowrap">{row.createdAt || '—'}</td>
            <td>{row.env || '—'}</td>
            <td>
              <code>{row.scope || '—'}</code>
            </td>
            <td className="text-end">{formatBackupSize(row)}</td>
            <td className="text-nowrap">
              <span
                className={
                  isBackupChecksumMismatch(row)
                    ? 'text-danger fw-semibold'
                    : undefined
                }
              >
                {formatBackupChecksum(row)}
              </span>
            </td>
            <td className="text-end">{formatBackupDuration(row)}</td>
            <td style={{ minWidth: '10rem' }}>{statusCell(row)}</td>
            <td className="text-center">
              {isBackupKeepable(row) ? (
                <div className="form-check form-switch d-inline-block m-0">
                  <input
                    type="checkbox"
                    className="form-check-input"
                    role="switch"
                    checked={row.keep}
                    disabled={keep.busy && keepPendingId === row.id}
                    aria-label={
                      row.keep ? 'Unpin from rotation' : 'Pin out of rotation'
                    }
                    title={
                      row.keep
                        ? 'Pinned out of rotation'
                        : 'Pin out of rotation'
                    }
                    data-id={`hilos-backup-keep-${row.id}`}
                    onChange={() => void toggleKeep(row)}
                  />
                </div>
              ) : (
                <span className="text-body-secondary">—</span>
              )}
            </td>
            <td className="text-end">
              {hasBackupFailureDetail(row) ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary me-1"
                  title="Show failure reason"
                  aria-label="Show failure reason"
                  data-id={`hilos-backup-details-${row.id}`}
                  onClick={() => openDetails(row)}
                >
                  <i className="bi bi-exclamation-circle" aria-hidden="true" />
                </button>
              ) : null}
              {isBackupDeletable(row) ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-danger"
                  title="Delete backup"
                  aria-label="Delete backup"
                  data-id={`hilos-backup-delete-${row.id}`}
                  onClick={() => openDelete(row)}
                >
                  <i className="bi bi-trash" aria-hidden="true" />
                </button>
              ) : null}
            </td>
          </>
        )}
      />

      <HilosModal
        open={deleteOpen}
        title={deleteRow ? `Delete · ${deleteRow.id}` : 'Delete backup'}
        closeOnBackdrop={!del.busy}
        closeOnEsc={!del.busy}
        onClose={closeDelete}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={del.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={del.loading}
              data-id="hilos-backup-delete-confirm"
              onClick={() => void submitDelete()}
            >
              Delete
            </LoadingButton>
          </>
        )}
      >
        <p className="mb-0 text-body-secondary">
          This permanently deletes the backup archive and its metadata. A pinned
          backup is deleted too — the pin only protects it from rotation.
        </p>
        {deleteRow ? (
          <p className="mb-0 mt-2">
            <code>{deleteRow.id}</code>
          </p>
        ) : null}
      </HilosModal>

      <HilosModal
        open={detailsOpen}
        title={
          detailsRow ? `Backup failed · ${detailsRow.id}` : 'Backup failed'
        }
        onClose={closeDetails}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="hilos-backup-details-close"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <pre
          className="mb-0 small text-break"
          style={{ maxHeight: '60vh', overflowY: 'auto' }}
          data-id="hilos-backup-details-text"
        >
          {detailsRow?.failureReason}
        </pre>
      </HilosModal>
    </HilosAdminPage>
  )
}
