import { useLayoutEffect, useRef, useState } from 'react'
import {
  focusableElements,
  subscribeSignal,
  type ReadonlySignal,
} from '@hilos/core/base'
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { type AdminProductWarehouseEdit } from './adminProductWarehouseEdit.js'

const FORM_ID = 'admin-product-warehouse-form'
const WAREHOUSE_ID = 'admin-product-warehouse-choice'

/** The Overview's placement and move dialog, mounted for one opening. */
export function AdminProductWarehouseModal({
  edit,
  warehouses,
}: {
  edit: AdminProductWarehouseEdit
  warehouses: ReadonlySignal<readonly { value: number; label: string }[]>
}) {
  const { locked } = useLookOnly()
  const action = useTrackedAction()
  const title = useSignal(edit.title)
  const { warehouseId } = useSignal(edit.form)
  const choices = useSignal(warehouses)
  const state = useSignal(edit.state)
  const notice = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const saveLabel = useSignal(edit.saveLabel)
  const empty = choices.length === 0
  const unavailable =
    warehouseId !== null && !choices.some(({ value }) => value === warehouseId)
  const prompt = empty ? 'No warehouses yet' : 'Choose a warehouse'
  const form = useRef<HTMLFormElement>(null)
  const cancel = useRef<HTMLButtonElement>(null)
  const names = useRef(new Map<number, string>())
  const gone = state.gone
  const [controls, setControls] = useState({ gone, empty, saveAvailable })
  const warehouseOnDeletion =
    warehouseId === null
      ? ''
      : (names.current.get(warehouseId) ?? String(warehouseId))

  useLayoutEffect(() => {
    // A refusal drops the scope. Keep the latest name seen for each id in
    // this opening, including choices that disappeared before the refusal.
    const remember = (current: ReturnType<typeof warehouses.get>) => {
      for (const { value, label } of current) {
        if (label !== '') names.current.set(value, label)
      }
    }
    remember(warehouses.get())
    return subscribeSignal(warehouses, remember)
  }, [warehouses])

  useLayoutEffect(() => {
    if (
      controls.gone === gone &&
      controls.empty === empty &&
      controls.saveAvailable === saveAvailable
    )
      return

    // Hand off focus before removing or natively disabling its control.
    const currentForm = form.current
    const dialog = currentForm?.closest<HTMLElement>('[role="dialog"]')
    const focused = dialog?.ownerDocument.activeElement
    if (currentForm && dialog && focused && dialog.contains(focused)) {
      const warehouse = currentForm.elements.namedItem(WAREHOUSE_ID)
      const warehouseUnavailable = gone || empty
      const losesWarehouse =
        !controls.gone && warehouseUnavailable && focused === warehouse
      const losesSave =
        !saveAvailable &&
        focused instanceof HTMLButtonElement &&
        focused.form === currentForm
      if (losesWarehouse || losesSave) {
        const fallback = warehouseUnavailable ? cancel.current : warehouse
        const target =
          focusableElements(dialog).find((element) => element === fallback) ??
          dialog
        target.focus({ preventScroll: true })
      }
    }
    setControls({ gone, empty, saveAvailable })
  }, [controls, empty, gone, saveAvailable])

  return (
    <HilosModal
      open
      title={title}
      initialFocus="inner"
      onClose={edit.close}
      confirmOnClose={state.dirty}
      busy={saving}
      header={<ConflictHeader title={title} conflict={state.conflict} />}
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={state.conflict}
          onAcceptMine={edit.keepMine}
          onAcceptTheirs={edit.takeTheirs}
          cancelButton={
            <button
              ref={cancel}
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              onClick={requestClose}
            >
              Cancel
            </button>
          }
          saveButton={() => (
            <LoadingButton
              className="btn-primary"
              type="submit"
              form={FORM_ID}
              loading={saving}
              disabled={!controls.saveAvailable}
              aria-disabled={!saveAvailable || undefined}
              data-id="admin-product-warehouse-submit"
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <HilosActionError
        action={action}
        detailsTitle="Couldn't save the warehouse"
      />
      <form
        ref={form}
        id={FORM_ID}
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          if (!locked) void edit.save(action.run)
        }}
      >
        <label className="form-label" htmlFor={WAREHOUSE_ID}>
          Warehouse
        </label>
        {controls.gone ? (
          <input
            id={WAREHOUSE_ID}
            className="form-control"
            value={warehouseOnDeletion}
            readOnly
          />
        ) : (
          <select
            id={WAREHOUSE_ID}
            className="form-select"
            value={warehouseId ?? ''}
            required
            disabled={controls.empty}
            data-autofocus
            onChange={(event) => {
              const value = event.currentTarget.value
              edit.patchForm({
                warehouseId: value === '' ? null : Number(value),
              })
            }}
          >
            <option value="" disabled>
              {prompt}
            </option>
            {unavailable && (
              <option value={warehouseId} disabled>
                {empty && `${prompt} · `}Warehouse #{warehouseId} (unavailable)
              </option>
            )}
            {choices.map(({ value, label }) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        )}
        <HilosEditNotice
          kind={state.notice?.kind ?? null}
          text={notice}
          dataId="admin-product-warehouse-notice"
        />
      </form>
    </HilosModal>
  )
}
