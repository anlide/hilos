// Configuration owns the channel fields, their focused edit session and reset.
import { useContext, useEffect, useMemo, useState } from 'react'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  computedSignal,
} from '@hilos/core/base'
import {
  createHilosChannelFieldEdit,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  hilosChannelDisplayValue,
} from '@hilos/core/admin/communications'
import type {
  HilosChannelFieldRow,
  HilosCommunicationsContext,
} from '@hilos/core/admin/communications'

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
import { useHilosCommunicationsChannelHead } from './hilosCommunicationsChannelHead.js'

/** Props for {@link HilosCommunicationsChannelConfigurationPage}. */
export interface HilosCommunicationsChannelConfigurationPageProps {
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

/** The source badge label: where the effective value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  settings: 'Override',
  env: 'From env',
  default: 'Default',
}

/** The channel Configuration tab, with resources scoped to its route and context. */
export function HilosCommunicationsChannelConfigurationPage({
  context,
}: HilosCommunicationsChannelConfigurationPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosCommunicationsChannelConfigurationPage requires a provided Hilos router.',
    )
  }
  const route = useSignal(router.currentRoute)
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context) {
    setBinding({ context, revision: binding.revision + 1 })
  }
  return (
    <ChannelConfiguration
      key={`${binding.revision}:${route.params.channelId}`}
      context={context}
    />
  )
}

function ChannelConfiguration({
  context,
}: HilosCommunicationsChannelConfigurationPageProps) {
  const router = useContext(HilosRouterContext)!
  const { headProps } = useHilosCommunicationsChannelHead(context)

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

  const fields = useMemo(
    () =>
      createHilosChannelFields(
        context,
        channelSignal,
        HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
      ),
    [context, channelSignal],
  )
  const actions = useMemo(
    () => createHilosCommunicationsActions(context),
    [context],
  )

  // Edit dialog: one field's override value, as the core window has it; this
  // view binds the input.
  const editor = useMemo(
    () => createHilosChannelFieldEdit(fields.controller, actions),
    [fields, actions],
  )

  useEffect(() => {
    fields.start()
    editor.start()

    return () => {
      editor.dispose()
      fields.dispose()
    }
  }, [fields, editor])

  // Showing the backend's own phrase on a rejected write is the driver's default
  // since HIL-779; this page used to be the one screen that asked for it.
  const reset = useTrackedAction()
  const edit = useTrackedAction()

  const editOpen = useSignal(editor.opened)
  const editRow = useSignal(editor.row)
  const editForm = useSignal(editor.form)
  const live = useSignal(editor.state)
  const editNoticeText = useSignal(editor.noticeText)
  const editSaveLabel = useSignal(editor.saveLabel)
  const saveAvailable = useSignal(editor.saveAvailable)
  const editSaving = useSignal(editor.saving)
  const editInputType = inputType(editRow?.type)
  const editStep = editRow?.type === 'float' ? 'any' : undefined
  const editTitle = editRow ? `Edit · ${editRow.label}` : 'Edit field'
  const editHidden = editForm.hidden
  const editValue = editForm.text
  const setEditValue = (text: string) => editor.patchForm({ text })
  const editNotice = live.notice?.kind ?? null

  // The live row the open reset dialog is about: the row the table holds in
  // focus, which the server follows wherever it goes; undefined once the row is
  // gone.
  const liveRow = useSignal(fields.controller.focusedRow)

  // Reset dialog: back to env/default, only on confirm. It reads the same live
  // row the edit dialog does — one dialog is open at a time, and the focus is one.
  const [resetOpen, setResetOpen] = useState(false)
  const [resetRow, setResetRow] = useState<HilosChannelFieldRow | null>(null)
  const resetShown = liveRow ?? resetRow
  const resetGone = liveRow === undefined || liveRow.valueSource !== 'settings'

  function openEdit(row: HilosChannelFieldRow): void {
    // The window takes the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row removed by someone else (now
    // a placeholder) declines to open.
    edit.clearError()
    editor.open(row.key)
  }

  function closeEdit(): void {
    editor.close()
  }

  function acceptMine(): void {
    editor.keepMine()
  }

  function acceptTheirs(): void {
    editor.takeTheirs()
  }

  // Save and Enter go through the window's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with the entered value.
  function submitEdit(): void {
    void editor.save(edit.run)
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
    <HilosAdminPage
      page={HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION}
      {...headProps}
    >
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
                {(value) => <span>{hilosChannelDisplayValue(value)}</span>}
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
                  title="Reset to default"
                  aria-label="Reset to default"
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
        busy={editSaving}
        onClose={closeEdit}
        header={<ConflictHeader title={editTitle} conflict={live.conflict} />}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!saveAvailable}
            saveLabel={editSaveLabel}
            onSave={() => void submitEdit()}
            onAcceptMine={acceptMine}
            onAcceptTheirs={acceptTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={editSaving}
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={edit.busy}
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
        busy={reset.busy}
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
              loading={reset.busy}
              disabled={resetGone}
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
                {(value) => hilosChannelDisplayValue(value)}
              </HilosHideable>
            </dd>
            <dt className="col-4">Back to</dt>
            <dd
              className="col-8 text-break"
              data-id="hilos-channel-reset-default"
            >
              <HilosHideable value={resetShown.defaultValue}>
                {(value) => hilosChannelDisplayValue(value)}
              </HilosHideable>{' '}
              <span className="badge text-bg-secondary-subtle text-secondary-emphasis ms-2">
                {SOURCE_LABEL[resetShown.defaultValueSource] ??
                  resetShown.defaultValueSource}
              </span>
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
