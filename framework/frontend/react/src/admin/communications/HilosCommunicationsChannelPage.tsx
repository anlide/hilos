// HilosCommunicationsChannelPage — the framework Hilos channel-config page
// (HilosPages.COMMUNICATIONS_CHANNEL): one delivery channel's config fields inside
// the admin shell. The route {channelId} names the channel; the fields table is
// global (one row per field of every channel), so the core headless presets the
// channel in the table's filter map and the server narrows the window to it — no
// filter is applied on the client (createHilosChannelFields). The table is the
// shared server-windowed one, drawn from what it declares about its frame (its
// columns and empty words); this view owns only the cells of a row. Each editable
// field shows its effective value and source and can be overridden (edit, in a
// modal) or reset to its env/default — the ↺ asks first, in a confirm dialog
// built like the settings orphan delete; a secret is shown as set/not-set and never
// editable. A "Send test notification" button above the table exercises the real
// delivery path (HIL-201). Writes are tracked actions (createHilosCommunicationsActions):
// the value redraws from the reactive table's snapshot signal after the backend
// echo, never optimistically, and a validation failure surfaces as a toast with the
// backend's domain phrase. Editing happens in a modal — inline forms are forbidden
// (rules-and-violations.md section E) — and the modal merges against the live row
// through the shared row-edit helper (rowEdit.ts, conflict-resolution.md), saying
// what happened elsewhere on one line of room held in advance (HilosEditNotice).
// Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo, useState } from 'react'
import {
  HIDDEN_VALUE,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  computedSignal,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  hiddenAsWord,
  isHiddenValue,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  Hideable,
  HilosChannelFieldRow,
  HilosCommunicationsContext,
  RowEditBaseline,
  RowEditNoticeKind,
  RowEditStep,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosCommunicationsChannelPage}. */
export interface HilosCommunicationsChannelPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosCommunicationsContext
}

/** Map a field type to the value input it edits with. */
function inputType(type: string | undefined): 'text' | 'number' | 'checkbox' {
  if (type === 'boolean') {
    return 'checkbox'
  }
  if (type === 'integer' || type === 'float') {
    return 'number'
  }

  return 'text'
}

/** Human-readable effective value of a non-secret field. */
function displayValue(value: boolean | number | string | null): string {
  if (typeof value === 'boolean') {
    return value ? 'On' : 'Off'
  }

  return value === null || value === '' ? '—' : String(value)
}

/** The source badge label: where the effective value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  settings: 'Override',
  env: 'From env',
  default: 'Default',
}

/** Coerce the edited string to the field's typed value for the set action. */
/** Coerce the edited string to the field's typed value for the set action. */
function editedValue(
  row: HilosChannelFieldRow,
  raw: string,
): boolean | number | string {
  if (row.type === 'boolean') {
    return raw === '1'
  }
  if (row.type === 'integer' || row.type === 'float') {
    return Number(raw)
  }

  return raw
}

/** The one field the dialog edits: the field's typed value. */
interface ChannelEditFields {
  value: Hideable<boolean | number | string | null>
}

/**
 * The text the dialog's input shows for a typed value: a switch reads '1' /
 * '0', an empty value reads as nothing, anything else as itself.
 */
function formText(
  type: string,
  value: Hideable<boolean | number | string | null>,
): string {
  if (isHiddenValue(value)) {
    return ''
  }
  if (type === 'boolean') {
    return value === true ? '1' : '0'
  }

  return value === null ? '' : String(value)
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosChannelFieldRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return liveRow
        ? `Changed elsewhere to "${
            isHiddenValue(liveRow.value)
              ? hiddenAsWord(liveRow.value)
              : displayValue(liveRow.value)
          }".`
        : ''
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

/**
 * The framework channel-config page: the channel's config-fields table with a
 * test-notification button, a per-field edit modal, and a per-field reset.
 *
 * @param props The project context (connection, scope stores, action lifecycle).
 */
export function HilosCommunicationsChannelPage({
  context,
}: HilosCommunicationsChannelPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosCommunicationsChannelPage requires a provided router: <HilosRouterContext.Provider value={router}>.',
    )
  }

  // The route channel, as a core signal the fields table follows: navigating to
  // another channel sets the table's channel filter again and asks for its window.
  const channelSignal = useMemo(
    () =>
      computedSignal(
        () =>
          (router.currentRoute.get().params.channelId as string | undefined) ??
          '',
      ),
    [router],
  )
  const channel = useSignal(channelSignal)

  const fields = useMemo(
    () => createHilosChannelFields(context, channelSignal),
    [context, channelSignal],
  )
  const actions = useMemo(
    () => createHilosCommunicationsActions(context),
    [context],
  )

  useEffect(() => {
    fields.start()

    return () => fields.dispose()
  }, [fields])

  // Showing the backend's own phrase on a rejected write is the driver's default
  // since HIL-779; this page used to be the one screen that asked for it.
  const test = useTrackedAction()
  const reset = useTrackedAction()
  const edit = useTrackedAction()

  function sendTest(): void {
    void test.run(actions.sendChannelTest(channel))
  }

  // Edit dialog: one field's override value.
  const [editOpen, setEditOpen] = useState(false)
  const [editRow, setEditRow] = useState<HilosChannelFieldRow | null>(null)
  const [editBaseline, setEditBaseline] = useState<
    RowEditBaseline<ChannelEditFields>
  >(() => openRowEdit<ChannelEditFields>({ value: null }))
  const [editValue, setEditValue] = useState('')
  const editInputType = inputType(editRow?.type)
  const editStep = editRow?.type === 'float' ? 'any' : undefined
  const editTitle = editRow ? `Edit · ${editRow.label}` : 'Edit field'

  const editHidden = isHiddenValue(editBaseline.values.value)

  // The live row the open dialog is about: the row the table holds in focus, which
  // the server follows wherever it goes; undefined once the row is gone.
  const liveRow = useSignal(fields.controller.focusedRow)
  const live = resolveRowEdit(
    liveRow ? { value: liveRow.value } : undefined,
    editBaseline,
    {
      value: editHidden
        ? HIDDEN_VALUE
        : editRow
          ? editedValue(editRow, editValue)
          : null,
    },
  )
  const editNotice = live.notice?.kind ?? null
  const editNoticeText = noticeText(editNotice, liveRow)
  const editSaveLabel = live.gone ? 'Deleted' : 'Save'

  // Reset dialog: back to env/default, only on confirm. It reads the same live
  // row the edit dialog does — one dialog is open at a time, and the focus is one.
  const [resetOpen, setResetOpen] = useState(false)
  const [resetRow, setResetRow] = useState<HilosChannelFieldRow | null>(null)
  const resetShown = liveRow ?? resetRow
  const resetGone = liveRow === undefined || liveRow.valueSource !== 'settings'

  // Put a step of the helper into the dialog: the snapshot moves, and a value
  // the step takes lands in the input the way the dialog opened with it.
  function applyStep(
    row: HilosChannelFieldRow,
    step: RowEditStep<ChannelEditFields>,
  ): void {
    setEditBaseline(step.baseline)
    const taken = step.take.value
    if (taken !== undefined) {
      setEditValue(formText(row.type, taken))
    }
  }

  // The helper hands a step whenever the other side moved a field the person
  // left alone, or both arrived at the same value; the dialog applies it at once.
  const settle = live.settle
  useEffect(() => {
    if (editOpen && editRow && settle) {
      applyStep(editRow, settle)
    }
  }, [editOpen, editRow, settle])

  function openEdit(row: HilosChannelFieldRow): void {
    // Flush pending and take the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row removed by someone else (now
    // a placeholder) declines to open.
    const fresh = fields.controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    edit.clearError()
    setEditRow(fresh)
    setEditValue(
      isHiddenValue(fresh.value) ? '' : formText(fresh.type, fresh.value),
    )
    setEditBaseline(openRowEdit<ChannelEditFields>({ value: fresh.value }))
    setEditOpen(true)
  }

  function closeEdit(): void {
    setEditOpen(false)
    fields.controller.releaseFocus()
  }

  function acceptMine(): void {
    setEditBaseline(keepMineRowEdit(live, editBaseline))
  }

  function acceptTheirs(): void {
    if (editRow) {
      applyStep(editRow, takeTheirsRowEdit(live, editBaseline))
    }
  }

  async function submitEdit(): Promise<void> {
    if (!editRow || edit.busy || live.gone || live.conflict || editHidden) {
      return
    }
    if (!live.dirty) {
      closeEdit()

      return
    }
    if (
      await edit.run(
        actions.sendChannelSet(
          editRow.channel,
          editRow.field,
          editedValue(editRow, editValue),
        ),
      )
    ) {
      closeEdit()
    }
  }

  function openReset(row: HilosChannelFieldRow): void {
    // Flush pending and take the row into focus; a row already removed by someone
    // else does not open a reset.
    const fresh = fields.controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    reset.clearError()
    setResetRow(fresh)
    setResetOpen(true)
  }

  function closeReset(): void {
    setResetOpen(false)
    fields.controller.releaseFocus()
  }

  async function submitReset(): Promise<void> {
    if (!resetRow || reset.busy || resetGone) {
      return
    }
    if (
      await reset.run(
        actions.sendChannelReset(resetRow.channel, resetRow.field),
      )
    ) {
      closeReset()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.COMMUNICATIONS_CHANNEL}>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <p className="mb-0 text-body-secondary">
          Channel <code>{channel}</code>
        </p>
        <LoadingButton
          className="btn-outline-primary btn-sm"
          loading={test.loading}
          disabled={test.busy}
          data-id="hilos-channel-test"
          onClick={sendTest}
        >
          Send test notification
        </LoadingButton>
      </div>

      <HilosViewportTable
        controller={fields.controller}
        cells={{
          field: (row) => (
            <>
              <div className="fw-semibold">{row.label}</div>
              <code className="small text-body-secondary">{row.field}</code>
            </>
          ),
          value: (row) =>
            row.secret ? (
              <span className="text-body-secondary fst-italic">
                {row.valueSource === 'env' ? 'Set in env' : 'Not set'}
              </span>
            ) : (
              <HilosHideable value={row.value}>
                {(value) => <span>{displayValue(value)}</span>}
              </HilosHideable>
            ),
          valueSource: (row) => (
            <span className="badge text-bg-secondary-subtle text-secondary-emphasis">
              {SOURCE_LABEL[row.valueSource] ?? row.valueSource}
            </span>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) =>
            row.editable ? (
              <>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-primary"
                  title="Edit"
                  aria-label="Edit"
                  data-id={`hilos-channel-field-edit-${row.field}`}
                  onClick={() => openEdit(row)}
                >
                  <i className="bi bi-pencil" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  title="Reset to env/default"
                  aria-label="Reset to env/default"
                  disabled={row.valueSource !== 'settings'}
                  data-id={`hilos-channel-field-reset-${row.field}`}
                  onClick={() => openReset(row)}
                >
                  <i
                    className="bi bi-arrow-counterclockwise"
                    aria-hidden="true"
                  />
                </button>
              </>
            ) : null,
        }}
      />

      <HilosModal
        open={editOpen}
        confirmOnClose={live.dirty}
        onClose={closeEdit}
        header={<ConflictHeader title={editTitle} conflict={live.conflict} />}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!live.dirty || edit.busy || live.gone || editHidden}
            saveLabel={editSaveLabel}
            onSave={() => void submitEdit()}
            onAcceptMine={acceptMine}
            onAcceptTheirs={acceptTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={edit.busy}
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={edit.loading}
                disabled={disabled}
                data-id="hilos-channel-edit-save"
                onClick={onSave}
              >
                {editSaveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosActionError action={edit} detailsTitle="Couldn't save" />
        {editRow ? (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              void submitEdit()
            }}
          >
            {editHidden ? (
              <div className="mb-3">
                <div className="form-label">{editRow.label}</div>
                <HilosHiddenMark />
              </div>
            ) : editInputType === 'checkbox' ? (
              <div className="form-check form-switch">
                <input
                  id="hilos-channel-edit-value"
                  type="checkbox"
                  className="form-check-input"
                  role="switch"
                  data-id="hilos-channel-edit-value"
                  data-autofocus
                  checked={editValue === '1'}
                  onChange={(event) =>
                    setEditValue(event.target.checked ? '1' : '0')
                  }
                />
                <label
                  className="form-check-label"
                  htmlFor="hilos-channel-edit-value"
                >
                  {editRow.label}
                </label>
              </div>
            ) : (
              <>
                <label
                  className="form-label"
                  htmlFor="hilos-channel-edit-value"
                >
                  {editRow.label}
                </label>
                <input
                  id="hilos-channel-edit-value"
                  type={editInputType}
                  step={editStep}
                  className="form-control"
                  data-id="hilos-channel-edit-value"
                  data-autofocus
                  value={editValue}
                  onChange={(event) => setEditValue(event.target.value)}
                />
              </>
            )}
            <HilosEditNotice
              kind={editNotice}
              text={editNoticeText}
              dataId="hilos-channel-edit-notice"
            />
          </form>
        ) : null}
      </HilosModal>

      <HilosModal
        open={resetOpen}
        title={resetRow ? `Reset · ${resetRow.label}` : 'Reset field'}
        closeOnBackdrop={!reset.busy}
        closeOnEsc={!reset.busy}
        initialFocus="dialog"
        onClose={closeReset}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={reset.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={reset.loading}
              disabled={reset.busy || resetGone}
              data-id="hilos-channel-reset-confirm"
              onClick={() => void submitReset()}
            >
              Reset
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={reset}
          detailsTitle="Couldn't reset the field"
        />
        {resetShown ? (
          <dl className="row mb-0">
            <dt className="col-4">Now</dt>
            <dd className="col-8" data-id="hilos-channel-reset-now">
              <HilosHideable value={resetShown.value}>
                {(value) => displayValue(value)}
              </HilosHideable>
            </dd>
            <dt className="col-4">Back to</dt>
            <dd className="col-8" data-id="hilos-channel-reset-default">
              the env value, or the default when env has none
            </dd>
          </dl>
        ) : null}
        {resetGone ? (
          <p
            className="mb-0 mt-2 text-body-secondary"
            data-id="hilos-channel-reset-gone"
          >
            Already reset elsewhere.
          </p>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
