// HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
// stored-backup list inside the admin shell, with its row actions. The list is
// live — rows arrive over the socket from the backup runtime index plus the
// single in-progress backup, so an in-progress row shows a live progress bar until
// it completes and merges into the index. The bar is drawn from the phase anchors
// the row carries and a page-wide one-second clock, and falls back to the
// indeterminate striped bar on a run the backend cannot estimate. Its actions (create
// with a scope picker, per-row delete, per-row keep toggle, per-row restore) are
// the core headless's (createHilosBackupsActions); each dispatches a tracked action
// and surfaces the backend's failure (authoritative-backend). Restore is the
// destructive one: it is offered as a button only where the backend says so
// (everywhere but production), it confirms by typing the archive id, and while it
// runs the addressed progress frames are the only live thing on the page — the node
// is frozen and the table sends nothing. All table logic and the
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
  BACKUP_SHIP_STATE_FIELD,
  BACKUP_DURATION_SECONDS_FIELD,
  BACKUP_STATUS_FIELD,
  BACKUP_RESTORE_OUTCOME_FIELD,
  BACKUP_KEEP_FIELD,
  backupMigrationBehind,
  backupMigrationNotes,
  backupProgressPercent,
  backupRowAnchors,
  createBackupProgressClock,
  createHilosBackupsActions,
  createHilosBackupsRestoreGate,
  createHilosBackupsTable,
  createHilosRestoreProgress,
  formatBackupChecksum,
  formatBackupShipping,
  formatBackupDuration,
  formatBackupProgressLabel,
  formatBackupSize,
  formatRestoreCliCommand,
  hasBackupFailureDetail,
  hasRestoreOutcome,
  isBackupChecksumMismatch,
  isBackupShipFailed,
  isBackupDeletable,
  isBackupInProgress,
  isBackupKeepable,
  isBackupMigrationRefused,
  isBackupRestorable,
  isBackupSubsystemBusy,
  offersBackupRestore,
} from '@hilos/core'
import type {
  HilosBackupRow,
  HilosBackupsContext,
  HilosProgressAnchors,
  HilosTableColumnOf,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
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
  { key: BACKUP_SHIP_STATE_FIELD, label: 'Copy' },
  {
    key: BACKUP_DURATION_SECONDS_FIELD,
    label: 'Duration',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: BACKUP_STATUS_FIELD, label: 'Status', sortable: true },
  { key: BACKUP_RESTORE_OUTCOME_FIELD, label: 'Restore' },
  { key: BACKUP_KEEP_FIELD, label: 'Keep', headerClass: 'text-center' },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/**
 * The progress bar of a running backup or restore: a determinate bar once the run can
 * be estimated, and the indeterminate striped one until then. The caption under it
 * names the phase, the percentage, and the time left.
 *
 * @param anchors The progress anchors of the run (a table row's or a restore frame's).
 * @param nowMs The current epoch milliseconds the percentage is measured against.
 * @param className Extra classes for the bar's own element (spacing at its use site).
 * @param label The accessible name of the bar, which its caption does not provide.
 */
function progressBar(
  anchors: HilosProgressAnchors,
  nowMs: number,
  className: string,
  label: string,
) {
  const percent = backupProgressPercent(anchors, nowMs)

  return (
    <>
      <div
        className={`progress ${className}`}
        role="progressbar"
        aria-label={label}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent ?? undefined}
        data-id="hilos-backup-progress-bar"
      >
        <div
          className={
            percent === null
              ? 'progress-bar progress-bar-striped progress-bar-animated'
              : 'progress-bar'
          }
          style={{ width: `${percent ?? 100}%` }}
        />
      </div>
      <div className="small" data-id="hilos-backup-progress-label">
        {formatBackupProgressLabel(anchors, nowMs)}
      </div>
    </>
  )
}

/** The backup status cell: a live progress bar, a success badge, or a failure badge. */
function statusCell(row: HilosBackupRow, nowMs: number) {
  if (isBackupInProgress(row)) {
    return progressBar(backupRowAnchors(row), nowMs, '', 'Backup progress')
  }
  if (row.finished === true) {
    return <span className="badge text-bg-success">{row.status}</span>
  }

  return <span className="badge text-bg-danger">{row.status}</span>
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
  const restoreProgress = useMemo(
    () => createHilosRestoreProgress(context.connection),
    [context],
  )
  const restoreGate = useSignal(
    useMemo(() => createHilosBackupsRestoreGate(context), [context]),
  )
  const restoreStatus = useSignal(restoreProgress.status)
  // One ticker for the whole page: a percentage moves with wall time, while the socket
  // only speaks on a change of phase, so every bar here redraws from this signal.
  const progressClock = useMemo(() => createBackupProgressClock(), [])
  const progressNow = useSignal(progressClock.now)
  const rows = useSignal(backups.controller.rows)
  const subsystemBusy = isBackupSubsystemBusy(
    rows.map((entry) => entry.row),
    restoreStatus,
  )

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount. The restore frames are addressed to this
  // connection and start arriving the moment it asks for a run.
  useEffect(() => {
    backups.start()
    restoreProgress.start()

    return () => {
      backups.dispose()
      restoreProgress.dispose()
      progressClock.dispose()
    }
  }, [backups, restoreProgress, progressClock])

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

  // Restore dialog: the destructive one. Confirmation is typing the archive's id —
  // the one barrier muscle memory cannot pass, and the one that makes the operator
  // read WHICH archive they picked, since the likely mistake here is restoring the
  // wrong one rather than clicking the wrong button.
  const [restoreOpen, setRestoreOpen] = useState(false)
  const [restoreRow, setRestoreRow] = useState<HilosBackupRow | null>(null)
  const [restoreTyped, setRestoreTyped] = useState('')
  const restore = useTrackedAction()
  const restoreConfirmed = restoreRow !== null && restoreTyped === restoreRow.id

  function openRestore(row: HilosBackupRow): void {
    restore.clearError()
    setRestoreRow(row)
    setRestoreTyped('')
    setRestoreOpen(true)
  }

  function closeRestore(): void {
    setRestoreOpen(false)
  }

  async function submitRestore(): Promise<void> {
    if (!restoreRow || restore.busy || !restoreConfirmed) {
      return
    }
    if (await restore.run(actions.sendBackupRestore(restoreRow.id))) {
      closeRestore()
    }
  }

  /**
   * Why an archive cannot be restored right now, or null when it can. The button
   * stays visible and carries this as its title, so the answer arrives before the
   * click rather than as a toast after it.
   *
   * @param row The backup row the button belongs to.
   */
  function restoreBlockedReason(row: HilosBackupRow): string | null {
    if (isBackupChecksumMismatch(row)) {
      return 'This archive does not match its recorded checksum'
    }
    // What makes the archive unusable forever comes before what the subsystem is
    // doing right now: waiting for the current run would not make this one restorable.
    if (isBackupMigrationRefused(row)) {
      return (
        row.restoreMigrationNotice ??
        'This archive was taken on newer code; there is no downgrade path'
      )
    }
    // The shared predicate has the last word on whether the archive may be replayed at
    // all: a rule added there and not worded here must still disable the button.
    if (!isBackupRestorable(row)) {
      return 'This archive cannot be restored'
    }

    return subsystemBusy
      ? 'The backup subsystem is busy; wait for the current run to end'
      : null
  }

  // CLI instruction dialog: what the production surface offers instead of a button.
  const [cliOpen, setCliOpen] = useState(false)
  const [cliRow, setCliRow] = useState<HilosBackupRow | null>(null)
  const [cliCopied, setCliCopied] = useState(false)

  function openCli(row: HilosBackupRow): void {
    setCliRow(row)
    setCliCopied(false)
    setCliOpen(true)
  }

  async function copyCliCommand(): Promise<void> {
    if (!cliRow) {
      return
    }
    await navigator.clipboard.writeText(formatRestoreCliCommand(cliRow))
    setCliCopied(true)
  }

  // Restore-outcome dialog: how the last restore of this archive ended, read from
  // the row, so it survives the reload the successful path ends with.
  const [outcomeOpen, setOutcomeOpen] = useState(false)
  const [outcomeRow, setOutcomeRow] = useState<HilosBackupRow | null>(null)

  function openOutcome(row: HilosBackupRow): void {
    setOutcomeRow(row)
    setOutcomeOpen(true)
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

      {restoreStatus ? (
        <div
          className={`alert ${
            restoreStatus.outcome === 'error'
              ? 'alert-danger'
              : restoreStatus.outcome === 'success'
                ? 'alert-success'
                : 'alert-info'
          }`}
          role="status"
          data-id="hilos-backup-restore-phase"
        >
          <div className="fw-semibold">
            Restore {restoreStatus.backupId} · {restoreStatus.phase}
          </div>
          {restoreStatus.outcome === 'success' ? (
            <div className="small">
              Restored. This page reloads itself as soon as the system reopens.
            </div>
          ) : null}
          {restoreStatus.outcome === 'error' ? (
            <div className="small">
              <div>{restoreStatus.failureReason}</div>
              {restoreStatus.databaseTouched ? (
                <div>
                  The database was already being replaced when this failed.
                </div>
              ) : null}
              {!restoreStatus.rehydrateComplete ? (
                <div>
                  The system stays closed: not every process re-read the
                  replaced database. Reopen it from the CLI with{' '}
                  <code>php cli.php protected-mode:open</code>.
                </div>
              ) : null}
            </div>
          ) : null}
          {restoreStatus.outcome === null
            ? progressBar(
                restoreStatus,
                progressNow,
                'mt-2',
                'Restore progress',
              )
            : null}
        </div>
      ) : null}

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
            <td className="text-nowrap">
              <span
                className={
                  isBackupShipFailed(row)
                    ? 'text-danger fw-semibold'
                    : undefined
                }
                title={row.shipError ?? undefined}
              >
                {formatBackupShipping(row)}
              </span>
            </td>
            <td className="text-end">{formatBackupDuration(row)}</td>
            <td style={{ minWidth: '10rem' }}>
              {statusCell(row, progressNow)}
            </td>
            <td className="text-nowrap">
              {hasRestoreOutcome(row) ? (
                <button
                  type="button"
                  className="btn btn-sm p-0 border-0 bg-transparent"
                  title={`Show how the restore of ${row.id} ended`}
                  data-id={`hilos-backup-restore-outcome-${row.id}`}
                  onClick={() => openOutcome(row)}
                >
                  <span
                    className={`badge ${
                      row.restoreOutcome === 'success'
                        ? 'text-bg-success'
                        : 'text-bg-danger'
                    }`}
                  >
                    {row.restoreOutcome}
                  </span>
                </button>
              ) : row.restorePhase ? (
                <span className="badge text-bg-info">{row.restorePhase}</span>
              ) : /* What happened to this archive outranks what could: the badge
              speaks only where no restore of it has anything to report. */
              isBackupMigrationRefused(row) ? (
                <span
                  className="badge text-bg-danger"
                  data-id={`hilos-backup-migration-${row.id}`}
                >
                  incompatible
                </span>
              ) : backupMigrationBehind(row) !== null ? (
                <span
                  className="badge text-bg-warning"
                  data-id={`hilos-backup-migration-${row.id}`}
                >
                  +{backupMigrationBehind(row)} migrations
                </span>
              ) : (
                <span className="text-body-secondary">—</span>
              )}
            </td>
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
              {offersBackupRestore(row) && restoreGate.uiEnabled ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-warning me-1"
                  disabled={restoreBlockedReason(row) !== null}
                  title={restoreBlockedReason(row) ?? 'Restore this backup'}
                  aria-label="Restore this backup"
                  data-id={`hilos-backup-restore-${row.id}`}
                  onClick={() => openRestore(row)}
                >
                  <i
                    className="bi bi-arrow-counterclockwise"
                    aria-hidden="true"
                  />
                </button>
              ) : null}
              {offersBackupRestore(row) && !restoreGate.uiEnabled ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary me-1"
                  title="How to restore this backup"
                  aria-label="How to restore this backup"
                  data-id={`hilos-backup-restore-cli-${row.id}`}
                  onClick={() => openCli(row)}
                >
                  <i className="bi bi-terminal" aria-hidden="true" />
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

      <HilosModal
        open={restoreOpen}
        title={restoreRow ? `Restore · ${restoreRow.id}` : 'Restore backup'}
        closeOnBackdrop={!restore.busy}
        closeOnEsc={!restore.busy}
        onClose={closeRestore}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={restore.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-warning"
              loading={restore.loading}
              disabled={!restoreConfirmed}
              data-id="hilos-backup-restore-confirm"
              onClick={() => void submitRestore()}
            >
              Restore
            </LoadingButton>
          </>
        )}
      >
        <p className="mb-2">
          This overwrites every database of this installation with the contents
          of the archive. Everyone else is shown a maintenance screen until it
          ends, and if the system does not come back on its own it is reopened
          from the CLI.
        </p>
        {restoreRow ? (
          <p className="mb-2 text-body-secondary">
            Archive taken in{' '}
            <code>{restoreRow.env || 'an unnamed environment'}</code> → this
            installation is <code>{restoreGate.targetEnv || 'unnamed'}</code>
          </p>
        ) : null}
        {restoreRow && backupMigrationNotes(restoreRow).length > 0 ? (
          <ul
            className="mb-2 ps-3 text-body-secondary"
            data-id="hilos-backup-migration-notes"
          >
            {backupMigrationNotes(restoreRow).map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        ) : null}
        <label className="form-label" htmlFor="hilos-backup-restore-id">
          Type the archive id to confirm
        </label>
        <input
          id="hilos-backup-restore-id"
          type="text"
          className="form-control"
          autoComplete="off"
          disabled={restore.busy}
          placeholder={restoreRow?.id}
          data-id="hilos-backup-restore-id"
          value={restoreTyped}
          onChange={(event) => setRestoreTyped(event.target.value)}
        />
      </HilosModal>

      <HilosModal
        open={cliOpen}
        title={cliRow ? `How to restore · ${cliRow.id}` : 'How to restore'}
        onClose={() => setCliOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              data-id="hilos-backup-restore-cli-copy"
              onClick={() => void copyCliCommand()}
            >
              {cliCopied ? 'Copied' : 'Copy'}
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={requestClose}
            >
              Close
            </button>
          </>
        )}
      >
        <p className="mb-2 text-body-secondary">
          Restoring is not offered from the browser on this environment. Run
          this on the machine that hosts the installation:
        </p>
        <pre
          className="mb-0 small text-break"
          data-id="hilos-backup-restore-cli-text"
        >
          {cliRow ? formatRestoreCliCommand(cliRow) : ''}
        </pre>
        {/* The same lines the button's title carries where there is a button: an
        operator on production learns of an incompatible archive here, not from the
        command refusing after they have walked to the terminal. */}
        {cliRow && backupMigrationNotes(cliRow).length > 0 ? (
          <ul
            className="mt-2 mb-0 ps-3 text-body-secondary"
            data-id="hilos-backup-migration-cli-notes"
          >
            {backupMigrationNotes(cliRow).map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        ) : null}
      </HilosModal>

      <HilosModal
        open={outcomeOpen}
        title={
          outcomeRow ? `Restore · ${outcomeRow.id}` : 'Restore of this backup'
        }
        onClose={() => setOutcomeOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <p className="mb-2">
          Finished {outcomeRow?.restoreFinishedAt || '—'} ·{' '}
          <span className="fw-semibold">{outcomeRow?.restoreOutcome}</span>
        </p>
        {outcomeRow?.restoreDatabaseTouched ? (
          <p className="mb-2">
            The database was already being replaced when this run ended.
          </p>
        ) : null}
        <pre
          className="mb-0 small text-break"
          style={{ maxHeight: '60vh', overflowY: 'auto' }}
          data-id="hilos-backup-restore-outcome-text"
        >
          {outcomeRow?.restoreFailureReason || 'No failure recorded.'}
        </pre>
      </HilosModal>
    </HilosAdminPage>
  )
}
