import { useId, useLayoutEffect, useRef, useState } from 'react'
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

import { type CartQuantityEdit } from './cartQuantityEdit.js'

/**
 * Edit the quantity of a saved cart line against its live snapshot.
 *
 * @param props The session for this cart's exact-quantity edit.
 */
export function CartQuantityEditModal({ edit }: { edit: CartQuantityEdit }) {
  const formId = useId()
  const quantityId = useId()
  const helpId = useId()
  const opened = useSignal(edit.opened)
  const openingRow = useSignal(edit.row)
  const live = useSignal(edit.live)
  const form = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const action = useTrackedAction({ toast: false })
  const { locked } = useLookOnly()
  const saveButton = useRef<HTMLButtonElement>(null)
  const [nativeSaveAvailable, setNativeSaveAvailable] = useState(saveAvailable)
  const title = `Quantity: ${openingRow?.name ?? ''}`

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
      title={title}
      onClose={() => {
        if (!edit.saving.get()) edit.close()
      }}
      busy={saving}
      confirmOnClose={state.dirty}
      header={<ConflictHeader title={title} conflict={state.conflict} />}
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
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={() => (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form={formId}
                loading={saving}
                disabled={!nativeSaveAvailable}
                aria-disabled={!saveAvailable || undefined}
                onFocus={(event) => {
                  saveButton.current = event.currentTarget
                }}
              >
                {state.gone ? 'Deleted' : 'Set'}
              </LoadingButton>
            )}
          />
        </fieldset>
      )}
    >
      <HilosActionError action={action} detailsTitle="Couldn't set quantity" />
      <form
        id={formId}
        onSubmit={(event) => {
          event.preventDefault()
          if (!locked) void edit.save(action.run)
        }}
      >
        <label className="form-label" htmlFor={quantityId}>
          Quantity
        </label>
        <input
          id={quantityId}
          className="form-control"
          type="text"
          inputMode="numeric"
          required
          aria-describedby={helpId}
          data-autofocus
          value={form.quantity}
          onChange={(event) => {
            edit.patchForm({ quantity: event.currentTarget.value })
          }}
        />
        <div className="form-text" id={helpId}>
          {live?.name ?? openingRow?.name}
          {live !== undefined && ` · ${live.availableQuantity} in stock`}
        </div>
        <HilosEditNotice
          kind={state.notice?.kind ?? null}
          text={noticeText}
          dataId="shop-cart-quantity-edit-notice"
        />
      </form>
    </HilosModal>
  )
}
