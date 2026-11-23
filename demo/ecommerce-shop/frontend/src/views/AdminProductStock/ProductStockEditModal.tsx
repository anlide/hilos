import { useLayoutEffect, useRef, useState } from 'react'
import { focusInitial } from '@hilos/core/base'
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

import { type ProductStockEdit } from './productStockEdit.js'

const FORM_ID = 'admin-product-stock-edit-form'
const UNITS_ID = 'admin-product-stock-edit-units'
const HELP_ID = 'admin-product-stock-edit-help'

/**
 * The Set stock dialog, bound to the live warehouse and the tracked save.
 *
 * @param props The edit session for the current product's stock table.
 */
export function ProductStockEditModal({ edit }: { edit: ProductStockEdit }) {
  const opened = useSignal(edit.opened)
  const openingRow = useSignal(edit.row)
  const live = useSignal(edit.live)
  const form = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const saveLabel = useSignal(edit.saveLabel)
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const saveButton = useRef<HTMLButtonElement>(null)
  const [nativeSaveAvailable, setNativeSaveAvailable] = useState(saveAvailable)

  useLayoutEffect(() => {
    const button = saveButton.current
    if (
      !saveAvailable &&
      button &&
      button.ownerDocument.activeElement === button
    ) {
      const dialog = button.closest<HTMLElement>('[role="dialog"]')
      if (dialog) focusInitial(dialog)
    }
    setNativeSaveAvailable(saveAvailable)
  }, [saveAvailable])

  return (
    <HilosModal
      open={opened}
      title="Set stock"
      onClose={() => {
        if (!edit.saving.get()) edit.close()
      }}
      busy={saving}
      confirmOnClose={state.dirty}
      header={<ConflictHeader title="Set stock" conflict={state.conflict} />}
      actions={({ requestClose }) => (
        <fieldset className="p-0 border-0" disabled={saving && state.conflict}>
          <ConflictActions
            conflict={state.conflict}
            onAcceptMine={edit.keepMine}
            onAcceptTheirs={edit.takeTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={saving}
                data-id="admin-product-stock-edit-cancel"
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
                disabled={!nativeSaveAvailable}
                aria-disabled={!saveAvailable || undefined}
                data-id="admin-product-stock-edit-submit"
                onFocus={(event) => {
                  saveButton.current = event.currentTarget
                }}
              >
                {saveLabel}
              </LoadingButton>
            )}
          />
        </fieldset>
      )}
    >
      <HilosActionError action={action} detailsTitle="Couldn't set stock" />
      <form
        id={FORM_ID}
        data-id="admin-product-stock-edit-form"
        onSubmit={(event) => {
          event.preventDefault()
          if (!locked && edit.canSave.get()) void edit.save(action.run)
        }}
      >
        <label className="form-label" htmlFor={UNITS_ID}>
          Units in {(live ?? openingRow)?.warehouseName}
        </label>
        <input
          id={UNITS_ID}
          className="form-control"
          type="text"
          inputMode="numeric"
          aria-describedby={HELP_ID}
          data-id="admin-product-stock-edit-units"
          data-autofocus
          value={form.units}
          readOnly={saving}
          onChange={(event) => {
            edit.patchForm({ units: event.currentTarget.value })
          }}
        />
        <div className="form-text" id={HELP_ID}>
          What is on the shelf now. Buyers see the total of all warehouses.
        </div>
        <HilosEditNotice
          kind={state.notice?.kind ?? null}
          text={noticeText}
          dataId="admin-product-stock-edit-notice"
        />
      </form>
    </HilosModal>
  )
}
