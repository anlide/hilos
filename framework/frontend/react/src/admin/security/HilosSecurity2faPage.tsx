// HilosSecurity2faPage — the framework two-step verification admin page
// (HilosPages.SECURITY_2FA, HIL-494): the six second-factor settings, one row
// each — who must use it, the days a device is trusted, the size of a set of
// backup codes, and the removal wait with its bounds. Each row shows its value in
// words and a pencil; the pencil opens a modal (the modal-only editing rule), and
// a value a setting's rule refuses stays in the modal with the refusal above it.
// The table, the row view-model, the edit round-trip and the words are the core
// headless's; this view owns only the markup, so a project mounts it by passing
// its HilosTwoFactorContext. The screen is built from text: the mockup's node is
// a debt (D-115). Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HILOS_STEP_UP_ADMIN_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
} from '@hilos/core'
import type {
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
  HilosStepUpOperationRow,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosSecurity2faPage}. */
export interface HilosSecurity2faPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosTwoFactorContext
}

/**
 * The name of a setting on the screen.
 *
 * @param row The row of the setting.
 */
function labelOf(row: HilosTwoFactorSettingRow): string {
  return HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
}

/**
 * The two-step verification admin page.
 *
 * @param props The project's two-step verification context.
 */
export function HilosSecurity2faPage({ context }: HilosSecurity2faPageProps) {
  const settings = useMemo(
    () => createHilosSecurityTwoFactorTable(context),
    [context],
  )
  const actions = useMemo(
    () => createHilosSecurityTwoFactorActions(context),
    [context],
  )
  const operations = useMemo(
    () => createHilosSecurityStepUpTable(context),
    [context],
  )
  const operationActions = useMemo(
    () => createHilosSecurityStepUpActions(context),
    [context],
  )

  useEffect(() => {
    settings.start()
    operations.start()

    return () => {
      settings.dispose()
      operations.dispose()
    }
  }, [settings, operations])

  const operationToggle = useTrackedAction()
  const [pendingOperationKey, setPendingOperationKey] = useState<string | null>(
    null,
  )

  async function toggleOperation(
    row: HilosStepUpOperationRow,
    enabled: boolean,
  ): Promise<void> {
    setPendingOperationKey(row.operationKey)
    try {
      await operationToggle.run(
        operationActions.sendOperationSet(row.operationKey, enabled),
      )
    } finally {
      setPendingOperationKey(null)
    }
  }

  // The edit modal: one setting at a time, its value as typed until Save.
  const [editOpen, setEditOpen] = useState(false)
  const [editRow, setEditRow] = useState<HilosTwoFactorSettingRow | null>(null)
  const [editValue, setEditValue] = useState('')
  const edit = useTrackedAction()

  function openEdit(row: HilosTwoFactorSettingRow): void {
    edit.clearError()
    setEditRow(row)
    setEditValue(row.value)
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
      await edit.run(actions.sendSettingSet(editRow.rowKey, editValue.trim()))
    ) {
      closeEdit()
    }
  }

  return (
    <HilosAdminPage page={HilosPages.SECURITY_2FA}>
      <HilosViewportTable
        controller={settings.controller}
        cells={{
          rowKey: (row) => (
            <>
              <div className="fw-semibold">{labelOf(row)}</div>
              <div className="small text-body-secondary">
                {HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.hint}
              </div>
            </>
          ),
          value: (row) => (
            <span data-id={`hilos-2fa-value-${row.rowKey}`}>
              {describeHilosSecondFactorSetting(row.rowKey, row.value)}
            </span>
          ),
          actions: (row) => (
            <button
              type="button"
              className="btn btn-sm btn-outline-primary"
              title="Edit"
              aria-label={`Edit ${labelOf(row)}`}
              data-id={`hilos-2fa-edit-${row.rowKey}`}
              onClick={() => openEdit(row)}
            >
              <i className="bi bi-pencil" aria-hidden="true"></i>
            </button>
          ),
        }}
      />

      <div className="mt-4">
        <HilosViewportTable
          dataId="hilos-step-up-table"
          controller={operations.controller}
          cells={{
            operationKey: (row) => (
              <span
                className="fw-semibold"
                data-id={`hilos-step-up-row-${row.operationKey}`}
              >
                {row.label}
              </span>
            ),
            owner: (row) => (
              <span className="badge text-bg-light border">
                {row.owner === 'framework'
                  ? HILOS_STEP_UP_ADMIN_COPY.framework
                  : HILOS_STEP_UP_ADMIN_COPY.project}
              </span>
            ),
            enabled: (row) => (
              <HilosSwitch
                className="mb-0"
                checked={row.enabled}
                busy={pendingOperationKey === row.operationKey}
                disabled={operationToggle.busy}
                aria-label={`Require confirmation for ${row.label}`}
                dataId={`hilos-step-up-switch-${row.operationKey}`}
                onToggle={(enabled) => void toggleOperation(row, enabled)}
              />
            ),
          }}
        />
      </div>

      <HilosModal
        open={editOpen}
        title={editRow ? labelOf(editRow) : 'Edit setting'}
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
              data-id="hilos-2fa-save"
              onClick={() => void submitEdit()}
            >
              Save
            </LoadingButton>
          </>
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
            <label className="form-label" htmlFor="hilos-2fa-input">
              {labelOf(editRow)}
            </label>
            {editRow.rowKey === HilosSecondFactorSettingKey.required ? (
              <select
                id="hilos-2fa-input"
                className="form-select"
                data-id="hilos-2fa-input"
                data-autofocus
                value={editValue}
                onChange={(event) => setEditValue(event.target.value)}
              >
                {HILOS_SECOND_FACTOR_REQUIRED_VALUES.map((value) => (
                  <option key={value} value={value}>
                    {HILOS_SECOND_FACTOR_REQUIRED_COPY[value]}
                  </option>
                ))}
              </select>
            ) : (
              <input
                id="hilos-2fa-input"
                type="number"
                inputMode="numeric"
                className="form-control"
                data-id="hilos-2fa-input"
                data-autofocus
                value={editValue}
                onChange={(event) => setEditValue(event.target.value)}
              />
            )}
            <p className="form-text mb-0">
              {HILOS_SECOND_FACTOR_SETTING_COPY[editRow.rowKey]?.hint} Default:{' '}
              {describeHilosSecondFactorSetting(
                editRow.rowKey,
                editRow.defaultValue,
              )}
              .
            </p>
          </form>
        ) : null}
      </HilosModal>
    </HilosAdminPage>
  )
}
