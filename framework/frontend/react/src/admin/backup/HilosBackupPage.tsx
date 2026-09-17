// HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
// stored-backup list inside the admin shell, with its row actions. The list is
// live — rows arrive over the socket from the backup runtime index, and a run in
// flight is not one of them: it is the table's own bar, which this view does not draw
// yet (HIL-814) and reads only to keep the create button honest. Its actions (create
// with a scope picker, per-row delete, per-row keep toggle, per-row restore) are
// the core headless's (createHilosBackupsActions); each dispatches a tracked action
// and surfaces the backend's failure (authoritative-backend). Restore is the
// destructive one: it is offered as a button only where the backend says so
// (everywhere but production), it confirms by typing the archive id, and while it
// runs the addressed progress frames are the only live thing on the page — the node
// is frozen and the table sends nothing. Once it ends, the node stands in a
// verification window, and the one browser that started the restore is offered the
// block that closes it — the backend answers that personally in the page-data section,
// so a second admin looking at the same page sees nothing. All table logic, the row
// view-model, and what the backup list declares about its frame — its search, the
// scope and period filters, its columns — are the core headless's too; this view
// owns only the markup, so a project mounts it by passing its HilosBackupsContext.
// Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_BACKUP_CIRCLE_COPY,
  HILOS_BACKUP_REOPEN_COPY,
  HILOS_BACKUP_SCOPES,
  HilosPages,
  BACKUP_CIRCLE_IDENTIFIER_FIELD,
  BACKUP_CIRCLE_ONLINE_FIELD,
  backupMigrationBehind,
  backupMigrationNotes,
  backupProgressPercent,
  createBackupProgressClock,
  createHilosBackupsActions,
  createHilosBackupsCircleTable,
  createHilosBackupsReopenGate,
  createHilosBackupsRestoreGate,
  createHilosBackupsTable,
  createHilosRestoreProgress,
  formatBackupChecksum,
  formatBackupShipping,
  formatBackupDuration,
  formatBackupOutOfReach,
  formatBackupProgressLabel,
  formatBackupRunCaption,
  formatBackupSize,
  formatRestoreCliCommand,
  formatRestoreOutcomeLine,
  hasBackupFailureDetail,
  hasRestoreOutcome,
  isBackupChecksumMismatch,
  isBackupShipFailed,
  isBackupDeletable,
  isBackupKeepable,
  isBackupMigrationRefused,
  isBackupOutOfReach,
  isBackupRestorable,
  isBackupSubsystemBusy,
  offersBackupRestore,
} from '@hilos/core'
import type {
  HilosBackupCircleRow,
  HilosBackupRow,
  HilosBackupsContext,
  HilosProgressAnchors,
  HilosTableColumnOf,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLongText } from '../../HilosLongText.js'
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

const CIRCLE_COLUMNS: HilosTableColumnOf<HilosBackupCircleRow>[] = [
  { key: BACKUP_CIRCLE_IDENTIFIER_FIELD, label: 'Address', sortable: true },
  { key: BACKUP_CIRCLE_ONLINE_FIELD, label: 'Signed in' },
  {
    key: 'actions',
    label: '',
    headerClass: 'text-end',
    // The remove button and its confirmation name the address.
    reads: [BACKUP_CIRCLE_IDENTIFIER_FIELD],
  },
]

/**
 * The progress bar of a running restore: a determinate bar once the run can be
 * estimated, and the indeterminate striped one until then. The caption under it names
 * the phase, the percentage, and the time left.
 *
 * A create run is not drawn here — it arrives as the table's own bar, already counted
 * on the server (HIL-820), and this view does not draw that yet (HIL-814).
 *
 * @param anchors The progress anchors of the restore frame.
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

/** The backup status cell: a success badge or a failure badge, every row having ended. */
function statusCell(row: HilosBackupRow) {
  return row.finished === true ? (
    <span className="badge text-bg-success">{row.status}</span>
  ) : (
    <span className="badge text-bg-danger">{row.status}</span>
  )
}

/**
 * A backup cell's classes, muted when another node holds the archive. The row element
 * belongs to the table, so the mark is worn by the cells this page draws inside it.
 *
 * @param row The backup row the cell belongs to.
 * @param base The cell's own classes, or undefined when it has none.
 */
function backupCellClass(
  row: HilosBackupRow,
  base: string | undefined,
): string | undefined {
  if (!isBackupOutOfReach(row)) {
    return base
  }

  return base === undefined
    ? 'text-body-secondary'
    : `${base} text-body-secondary`
}

/**
 * The framework backup admin page: the searchable, sortable backup list with a
 * create toolbar and per-row keep / delete actions.
 *
 * @param props The project context (scope stores + action lifecycle).
 */
export function HilosBackupPage({ context }: HilosBackupPageProps) {
  const backups = useMemo(() => createHilosBackupsTable(context), [context])
  // The page's second table, on the same page scope under its own key: who the operator
  // named to check the system after a restore.
  const circle = useMemo(
    () => createHilosBackupsCircleTable(context),
    [context],
  )
  const actions = useMemo(() => createHilosBackupsActions(context), [context])
  const restoreProgress = useMemo(
    () => createHilosRestoreProgress(context.connection),
    [context],
  )
  const restoreGate = useSignal(
    useMemo(() => createHilosBackupsRestoreGate(context), [context]),
  )
  // Whether THIS browser is the one that may end the verification window a restore left
  // the node in. Personal to the subscription, so a second admin's tab reads false.
  const reopenOffered = useSignal(
    useMemo(() => createHilosBackupsReopenGate(context), [context]),
  )
  const restoreStatus = useSignal(restoreProgress.status)
  // The ticker of the restore panel: its percentage moves with wall time, while its
  // addressed frame only speaks on a change of phase. The create run needs none of it —
  // its figures arrive counted on the table's bar.
  const progressClock = useMemo(() => createBackupProgressClock(), [])
  const progressNow = useSignal(progressClock.now)
  // The bar lives outside the window, so the button stays honest on every page of the
  // list rather than only on the one the run happened to be shown on.
  const tableProgress = useSignal(backups.controller.progress.table)
  const subsystemBusy = isBackupSubsystemBusy(tableProgress, restoreStatus)

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount. The restore frames are addressed to this
  // connection and start arriving the moment it asks for a run.
  useEffect(() => {
    backups.start()
    circle.start()
    restoreProgress.start()

    return () => {
      backups.dispose()
      circle.dispose()
      restoreProgress.dispose()
      progressClock.dispose()
    }
  }, [backups, circle, restoreProgress, progressClock])

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

  // Delete dialog: every row of this set is a run that ended, so every one can be deleted.
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

  // Blocked-restore dialog: the sentence behind the "why" button of a dark restore
  // button. It shows whatever restoreBlockedReason() returns rather than knowing the
  // cases, so a reason added there is shown here without a word changed.
  const [blockedOpen, setBlockedOpen] = useState(false)
  const [blockedRow, setBlockedRow] = useState<HilosBackupRow | null>(null)

  function openBlocked(row: HilosBackupRow): void {
    setBlockedRow(row)
    setBlockedOpen(true)
  }

  // Copy-failure dialog: why the last copy of an archive off the machine did not make
  // it. The word stays in the cell; the reason is one click away rather than in a title
  // no phone and no screen reader reaches.
  const [shipErrorOpen, setShipErrorOpen] = useState(false)
  const [shipErrorRow, setShipErrorRow] = useState<HilosBackupRow | null>(null)

  function openShipError(row: HilosBackupRow): void {
    setShipErrorRow(row)
    setShipErrorOpen(true)
  }

  // Reopen dialog: ending the verification window. Confirmation is the modal itself and
  // nothing more — typing the archive id guards restore against picking the WRONG target,
  // and reopening has no target to miss; the only mistake left is the click.
  const [reopenOpen, setReopenOpen] = useState(false)
  const reopen = useTrackedAction()

  function openReopen(): void {
    reopen.clearError()
    setReopenOpen(true)
  }

  function closeReopen(): void {
    setReopenOpen(false)
  }

  async function submitReopen(): Promise<void> {
    if (reopen.busy) {
      return
    }
    if (await reopen.run(actions.sendBackupReopen())) {
      closeReopen()
    }
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
   * stays visible and a live "why" button beside it opens this sentence, so the
   * answer arrives before the click rather than as a toast after it. It is not the
   * button's title: a disabled button gets no mouse events, so its title never shows
   * (docs/agents/frontend/accessibility.md).
   *
   * @param row The backup row the button belongs to.
   */
  function restoreBlockedReason(row: HilosBackupRow): string | null {
    // An archive on another node's disk is out of reach whatever else is true of it.
    const outOfReach = formatBackupOutOfReach(row)
    if (outOfReach !== null) {
      return outOfReach
    }
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

  // Circle dialogs. Adding takes one field and removing takes a confirmation, because the
  // two mistakes are different: a mistyped address is refused by the server and costs a
  // second try, while a removal is silent and only noticed after the next restore.
  const [circleAddOpen, setCircleAddOpen] = useState(false)
  const [circleAddIdentifier, setCircleAddIdentifier] = useState('')
  const circleAdd = useTrackedAction()

  function openCircleAdd(): void {
    circleAdd.clearError()
    setCircleAddIdentifier('')
    setCircleAddOpen(true)
  }

  function closeCircleAdd(): void {
    setCircleAddOpen(false)
  }

  async function submitCircleAdd(): Promise<void> {
    if (circleAdd.busy || circleAddIdentifier.trim() === '') {
      return
    }
    if (await circleAdd.run(actions.sendBackupCircleAdd(circleAddIdentifier))) {
      closeCircleAdd()
    }
  }

  const [circleRemoveOpen, setCircleRemoveOpen] = useState(false)
  const [circleRemoveRow, setCircleRemoveRow] =
    useState<HilosBackupCircleRow | null>(null)
  const circleRemove = useTrackedAction()

  function openCircleRemove(row: HilosBackupCircleRow): void {
    circleRemove.clearError()
    setCircleRemoveRow(row)
    setCircleRemoveOpen(true)
  }

  function closeCircleRemove(): void {
    setCircleRemoveOpen(false)
  }

  async function submitCircleRemove(): Promise<void> {
    if (!circleRemoveRow || circleRemove.busy) {
      return
    }
    if (
      await circleRemove.run(
        actions.sendBackupCircleRemove(circleRemoveRow.memberId),
      )
    ) {
      closeCircleRemove()
    }
  }

  // CLI instruction dialog: what the production surface offers instead of a button.
  const [cliOpen, setCliOpen] = useState(false)
  const [cliRow, setCliRow] = useState<HilosBackupRow | null>(null)

  function openCli(row: HilosBackupRow): void {
    setCliRow(row)
    setCliOpen(true)
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
              {formatRestoreOutcomeLine(restoreStatus)}
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

      {reopenOffered ? (
        <div
          className="alert alert-warning"
          role="status"
          data-id="hilos-backup-reopen-panel"
        >
          <div className="fw-semibold">{HILOS_BACKUP_REOPEN_COPY.title}</div>
          <div className="small">{HILOS_BACKUP_REOPEN_COPY.body}</div>
          <button
            type="button"
            className="btn btn-warning btn-sm mt-2"
            data-id="hilos-backup-reopen"
            onClick={openReopen}
          >
            {HILOS_BACKUP_REOPEN_COPY.button}
          </button>
        </div>
      ) : null}

      <div className="card mb-3" data-id="hilos-backup-circle-panel">
        <div className="card-body">
          <div className="d-flex align-items-start justify-content-between gap-2">
            <div>
              <div className="fw-semibold">
                {HILOS_BACKUP_CIRCLE_COPY.title}
              </div>
              <div className="small text-body-secondary">
                {HILOS_BACKUP_CIRCLE_COPY.rule}
              </div>
              <div className="small text-body-secondary">
                {HILOS_BACKUP_CIRCLE_COPY.volatile}
              </div>
            </div>
            <button
              type="button"
              className="btn btn-outline-primary btn-sm text-nowrap"
              data-id="hilos-backup-circle-add"
              onClick={openCircleAdd}
            >
              {HILOS_BACKUP_CIRCLE_COPY.addButton}
            </button>
          </div>
          <div className="mt-3">
            <HilosViewportTable
              dataId="hilos-backup-circle-table"
              label={HILOS_BACKUP_CIRCLE_COPY.title}
              controller={circle.controller}
              columns={CIRCLE_COLUMNS}
              emptyText={HILOS_BACKUP_CIRCLE_COPY.empty}
              row={(row) => (
                <>
                  <td data-id={`hilos-backup-circle-row-${row.identifier}`}>
                    {row.identifier}
                  </td>
                  <td>
                    <span
                      className={
                        row.online ? 'text-success' : 'text-body-secondary'
                      }
                      data-id={`hilos-backup-circle-online-${row.identifier}`}
                    >
                      {row.online
                        ? HILOS_BACKUP_CIRCLE_COPY.online
                        : HILOS_BACKUP_CIRCLE_COPY.offline}
                    </span>
                  </td>
                  <td className="text-end">
                    <button
                      type="button"
                      className="btn btn-sm btn-outline-danger"
                      title={HILOS_BACKUP_CIRCLE_COPY.removeTitle}
                      aria-label={HILOS_BACKUP_CIRCLE_COPY.removeTitle}
                      data-id={`hilos-backup-circle-remove-${row.identifier}`}
                      onClick={() => openCircleRemove(row)}
                    >
                      <i className="bi bi-trash" aria-hidden="true" />
                    </button>
                  </td>
                </>
              )}
            />
          </div>
        </div>
      </div>

      <HilosViewportTable
        controller={backups.controller}
        tableProgress={(progress) => formatBackupRunCaption(progress)}
        row={(row) => (
          <>
            <td className={backupCellClass(row, 'text-nowrap')}>
              {row.createdAt || '—'}
              {isBackupOutOfReach(row) ? (
                <>
                  {' '}
                  <span
                    className="badge text-bg-secondary"
                    title={formatBackupOutOfReach(row) ?? undefined}
                    data-id={`hilos-backup-holder-${row.id}`}
                  >
                    {row.holderNode}
                  </span>
                  <span className="visually-hidden">
                    {formatBackupOutOfReach(row)}
                  </span>
                </>
              ) : null}
            </td>
            <td className={backupCellClass(row, undefined)}>
              {row.env || '—'}
            </td>
            <td className={backupCellClass(row, undefined)}>
              <code>{row.scope || '—'}</code>
            </td>
            <td className={backupCellClass(row, 'text-end')}>
              {formatBackupSize(row)}
            </td>
            <td className={backupCellClass(row, 'text-nowrap')}>
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
            <td className={backupCellClass(row, 'text-nowrap')}>
              <span
                className={
                  isBackupShipFailed(row)
                    ? 'text-danger fw-semibold'
                    : undefined
                }
              >
                {formatBackupShipping(row)}
              </span>
              {isBackupShipFailed(row) && row.shipError ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary ms-1"
                  title="Why the copy failed"
                  aria-label="Why the copy failed"
                  data-id={`hilos-backup-ship-why-${row.id}`}
                  onClick={() => openShipError(row)}
                >
                  <i className="bi bi-question-circle" aria-hidden="true" />
                </button>
              ) : null}
            </td>
            <td className={backupCellClass(row, 'text-end')}>
              {formatBackupDuration(row)}
            </td>
            <td
              className={backupCellClass(row, undefined)}
              style={{ minWidth: '10rem' }}
            >
              {statusCell(row)}
            </td>
            <td className={backupCellClass(row, 'text-nowrap')}>
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
            <td className={backupCellClass(row, 'text-center')}>
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
                      row.keep ? 'Unpin from rotation' : 'Pin out of rotation'
                    }
                    data-id={`hilos-backup-keep-${row.id}`}
                    onChange={() => void toggleKeep(row)}
                  />
                </div>
              ) : (
                <span className="text-body-secondary">—</span>
              )}
            </td>
            <td className={backupCellClass(row, 'text-end')}>
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
                <>
                  {restoreBlockedReason(row) !== null ? (
                    <button
                      type="button"
                      className="btn btn-sm btn-outline-secondary me-1"
                      title="Why this backup cannot be restored"
                      aria-label="Why this backup cannot be restored"
                      data-id={`hilos-backup-blocked-why-${row.id}`}
                      onClick={() => openBlocked(row)}
                    >
                      <i className="bi bi-question-circle" aria-hidden="true" />
                    </button>
                  ) : null}
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-warning me-1"
                    disabled={restoreBlockedReason(row) !== null}
                    title="Restore this backup"
                    aria-label="Restore this backup"
                    data-id={`hilos-backup-restore-${row.id}`}
                    onClick={() => openRestore(row)}
                  >
                    <i
                      className="bi bi-arrow-counterclockwise"
                      aria-hidden="true"
                    />
                  </button>
                </>
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
        initialFocus="dialog"
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
        <HilosActionError action={del} />
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
        open={reopenOpen}
        title={HILOS_BACKUP_REOPEN_COPY.modalTitle}
        closeOnBackdrop={!reopen.busy}
        closeOnEsc={!reopen.busy}
        initialFocus="dialog"
        onClose={closeReopen}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={reopen.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-warning"
              loading={reopen.loading}
              data-id="hilos-backup-reopen-confirm"
              onClick={() => void submitReopen()}
            >
              Reopen
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={reopen} />
        <p className="mb-0 text-body-secondary">
          {HILOS_BACKUP_REOPEN_COPY.modalBody}
        </p>
      </HilosModal>

      <HilosModal
        open={detailsOpen}
        title={
          detailsRow ? `Backup failed · ${detailsRow.id}` : 'Backup failed'
        }
        initialFocus="dialog"
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
        <HilosLongText
          kind="prose"
          text={detailsRow?.failureReason ?? ''}
          dataId="hilos-backup-details-text"
        />
      </HilosModal>

      <HilosModal
        open={blockedOpen}
        title={
          blockedRow ? `Cannot restore · ${blockedRow.id}` : 'Cannot restore'
        }
        initialFocus="dialog"
        onClose={() => setBlockedOpen(false)}
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
        <HilosLongText
          kind="prose"
          text={blockedRow ? (restoreBlockedReason(blockedRow) ?? '') : ''}
          dataId="hilos-backup-blocked-reason-text"
        />
      </HilosModal>

      <HilosModal
        open={shipErrorOpen}
        title={
          shipErrorRow ? `Copy failed · ${shipErrorRow.id}` : 'Copy failed'
        }
        initialFocus="dialog"
        onClose={() => setShipErrorOpen(false)}
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
        <HilosLongText
          kind="prose"
          text={shipErrorRow?.shipError ?? ''}
          dataId="hilos-backup-ship-error-text"
        />
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
        <HilosActionError action={restore} />
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
          data-autofocus
          value={restoreTyped}
          onChange={(event) => setRestoreTyped(event.target.value)}
        />
      </HilosModal>

      <HilosModal
        open={cliOpen}
        title={cliRow ? `How to restore · ${cliRow.id}` : 'How to restore'}
        copyText={cliRow ? formatRestoreCliCommand(cliRow) : ''}
        initialFocus="dialog"
        onClose={() => setCliOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-primary"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <p className="mb-2 text-body-secondary">
          Restoring is not offered from the browser on this environment. Run
          this on the machine that hosts the installation:
        </p>
        <HilosLongText
          kind="output"
          text={cliRow ? formatRestoreCliCommand(cliRow) : ''}
          dataId="hilos-backup-restore-cli-text"
        />
        {/* What the "why" dialog of a dark restore button says where there is a
        button: an operator on production learns of an incompatible archive here, not
        from the command refusing after they have walked to the terminal. */}
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
        initialFocus="dialog"
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
        <HilosLongText
          kind="prose"
          text={outcomeRow?.restoreFailureReason || 'No failure recorded.'}
          dataId="hilos-backup-restore-outcome-text"
        />
      </HilosModal>

      <HilosModal
        open={circleAddOpen}
        title={HILOS_BACKUP_CIRCLE_COPY.addTitle}
        closeOnBackdrop={!circleAdd.busy}
        closeOnEsc={!circleAdd.busy}
        onClose={closeCircleAdd}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={circleAdd.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={circleAdd.loading}
              disabled={circleAddIdentifier.trim() === ''}
              data-id="hilos-backup-circle-add-confirm"
              onClick={() => void submitCircleAdd()}
            >
              Add
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={circleAdd} />
        <label className="form-label" htmlFor="hilos-backup-circle-add-field">
          {HILOS_BACKUP_CIRCLE_COPY.addField}
        </label>
        <input
          id="hilos-backup-circle-add-field"
          type="text"
          className="form-control"
          autoComplete="off"
          disabled={circleAdd.busy}
          data-id="hilos-backup-circle-add-field"
          data-autofocus
          value={circleAddIdentifier}
          onChange={(event) => setCircleAddIdentifier(event.target.value)}
        />
      </HilosModal>

      <HilosModal
        open={circleRemoveOpen}
        title={HILOS_BACKUP_CIRCLE_COPY.removeTitle}
        closeOnBackdrop={!circleRemove.busy}
        closeOnEsc={!circleRemove.busy}
        initialFocus="dialog"
        onClose={closeCircleRemove}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={circleRemove.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={circleRemove.loading}
              data-id="hilos-backup-circle-remove-confirm"
              onClick={() => void submitCircleRemove()}
            >
              Remove
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={circleRemove} />
        <p className="mb-0 text-body-secondary">
          {HILOS_BACKUP_CIRCLE_COPY.removeBody}
        </p>
        {circleRemoveRow ? (
          <p className="mb-0 mt-2">
            <code>{circleRemoveRow.identifier}</code>
          </p>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
