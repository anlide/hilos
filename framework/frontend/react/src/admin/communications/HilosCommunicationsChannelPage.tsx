// HilosCommunicationsChannelPage — the framework Hilos channel-config page
// (HilosPages.COMMUNICATIONS_CHANNEL): one delivery channel's config fields inside
// the admin shell. The route {channelId} names the channel; the fields table is
// global (one row per field of every channel), so the core headless filters it to
// this channel client-side (createHilosChannelFields). Each editable field shows its
// effective value and source and can be overridden (edit, in a modal) or reset to
// its env/default; a secret is shown as set/not-set and never editable. A "Send test
// notification" button exercises the real delivery path (HIL-201). Writes are tracked
// actions (createHilosCommunicationsActions): the value redraws from the reactive
// table's snapshot signal after the backend echo, never optimistically, and a
// validation failure surfaces as a toast with the backend's domain phrase. Editing
// happens in a modal — inline forms are forbidden (rules-and-violations.md section E).
// Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useMemo, useState } from 'react'
import {
  HilosPages,
  computedSignal,
  createHilosChannelFields,
  createHilosCommunicationsActions,
} from '@hilos/core'
import type {
  HilosChannelFieldRow,
  HilosCommunicationsContext,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
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
function displayValue(row: HilosChannelFieldRow): string {
  if (typeof row.value === 'boolean') {
    return row.value ? 'On' : 'Off'
  }

  return row.value === null || row.value === '' ? '—' : String(row.value)
}

/** The source badge label: where the effective value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  settings: 'Override',
  env: 'From env',
  default: 'Default',
}

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

  // The route channel, as a core signal so the filtered fields re-derive on
  // navigation without a re-fetch of the (shared) global fields table.
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
  const rows = useSignal(fields.rows)
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

  function resetField(row: HilosChannelFieldRow): void {
    void reset.run(actions.sendChannelReset(row.channel, row.field))
  }

  // Edit dialog: one field's override value.
  const [editOpen, setEditOpen] = useState(false)
  const [editRow, setEditRow] = useState<HilosChannelFieldRow | null>(null)
  const [editValue, setEditValue] = useState('')
  const editInputType = inputType(editRow?.type)
  const editStep = editRow?.type === 'float' ? 'any' : undefined

  function openEdit(row: HilosChannelFieldRow): void {
    edit.clearError()
    setEditRow(row)
    if (row.type === 'boolean') {
      setEditValue(row.value === true ? '1' : '0')
    } else {
      setEditValue(
        row.value === null || row.value === undefined ? '' : String(row.value),
      )
    }
    setEditOpen(true)
  }

  function closeEdit(): void {
    setEditOpen(false)
  }

  async function submitEdit(): Promise<void> {
    if (!editRow || edit.busy) {
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

      <div className="table-responsive">
        <table className="table table-striped align-middle mb-0">
          <caption className="visually-hidden">
            Channel configuration fields
          </caption>
          <thead>
            <tr>
              <th scope="col">Field</th>
              <th scope="col">Value</th>
              <th scope="col">Source</th>
              <th scope="col" className="text-end"></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.key} data-id={`hilos-channel-field-${row.field}`}>
                <td>
                  <div className="fw-semibold">{row.label}</div>
                  <code className="small text-body-secondary">{row.field}</code>
                </td>
                <td>
                  {row.secret ? (
                    <span className="text-body-secondary fst-italic">
                      {row.valueSource === 'env' ? 'Set in env' : 'Not set'}
                    </span>
                  ) : (
                    <span>{displayValue(row)}</span>
                  )}
                </td>
                <td>
                  <span className="badge text-bg-secondary-subtle text-secondary-emphasis">
                    {SOURCE_LABEL[row.valueSource] ?? row.valueSource}
                  </span>
                </td>
                <td className="text-end">
                  {row.editable ? (
                    <div className="d-flex gap-1 justify-content-end">
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
                        disabled={row.valueSource !== 'settings' || reset.busy}
                        data-id={`hilos-channel-field-reset-${row.field}`}
                        onClick={() => resetField(row)}
                      >
                        <i
                          className="bi bi-arrow-counterclockwise"
                          aria-hidden="true"
                        />
                      </button>
                    </div>
                  ) : null}
                </td>
              </tr>
            ))}
            {rows.length === 0 ? (
              <tr>
                <td colSpan={4} className="text-center text-muted py-4">
                  No configurable fields for this channel.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      <HilosModal
        open={editOpen}
        title={editRow ? `Edit · ${editRow.label}` : 'Edit field'}
        onClose={closeEdit}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={edit.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-primary"
              loading={edit.loading}
              disabled={edit.busy}
              data-id="hilos-channel-edit-save"
              onClick={() => void submitEdit()}
            >
              Save
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={edit} />
        {editRow ? (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              void submitEdit()
            }}
          >
            {editInputType === 'checkbox' ? (
              <div className="form-check form-switch">
                <input
                  id="hilos-channel-edit-value"
                  type="checkbox"
                  className="form-check-input"
                  role="switch"
                  data-id="hilos-channel-edit-value"
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
                  value={editValue}
                  onChange={(event) => setEditValue(event.target.value)}
                />
              </>
            )}
          </form>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
