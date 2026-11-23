// The Edit warehouse dialog: the Add fields filled with the row the pencil
// opened, over the core row-edit session — the conflict with Keep mine / Take
// theirs, "Updated just now" and "Deleted" are the session's, and the window
// keeps no edit store of its own. The driver toasts the outcome; a refusal is
// also drawn above the fields, so the dialog stays open with what was typed.
// The parent mounts one window per opening, so an earlier refusal never carries
// into the next one. Bootstrap classes only (styling-rules.md).
import { useLayoutEffect, useRef, useState, type MouseEvent } from 'react'
import {
  createSignal,
  focusInitial,
  registerFocusReturnFallback,
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

import { sendWarehouseDelete } from './adminWarehousesActions.js'
import { type WarehouseEdit } from './warehouseEdit.js'
import { WarehouseFormFields } from './WarehouseFormFields.js'

const FORM_ID = 'admin-warehouses-edit-form'
const EDIT_STOCK_REASON_ID = 'admin-warehouses-edit-stock-reason'
const DELETE_STOCK_REASON_ID = 'admin-warehouses-delete-stock-reason'
const STOCK_REASON = 'This warehouse keeps stock and cannot be deleted'

/**
 * Park focus before this layer's Cancel or Close becomes natively disabled.
 *
 * @param cancel The active layer's Cancel button.
 */
function parkClosingControl(cancel: HTMLButtonElement | null): void {
  if (!cancel) return
  const dialog = cancel.closest<HTMLElement>('[role="dialog"]')
  const focused = cancel.ownerDocument.activeElement
  if (
    dialog &&
    focused?.closest('[role="dialog"]') === dialog &&
    (focused === cancel || focused.matches('[data-id="modal-close"]'))
  ) {
    dialog.focus({ preventScroll: true })
  }
}

/** Reserve the stock explanation's space and announce it in the active modal. */
function WarehouseStockReason({
  id,
  visible,
  announce = visible,
}: {
  id: string
  visible: boolean
  announce?: boolean
}) {
  return (
    <>
      <div className={`mt-2 mb-0${visible ? '' : ' bg-body-tertiary rounded'}`}>
        <p
          id={id}
          className={`mb-0 small text-body-secondary${visible ? '' : ' invisible'}`}
          aria-hidden={!visible}
        >
          {STOCK_REASON}
        </p>
      </div>
      <span className="visually-hidden" role="status" aria-live="polite">
        {announce ? STOCK_REASON : ''}
      </span>
    </>
  )
}

/**
 * One opening of Edit warehouse and its delete confirmation, sharing the
 * session's live row and draft until the backend answers.
 *
 * @param edit The session over the warehouses table.
 */
export function WarehouseEditModal({ edit }: { edit: WarehouseEdit }) {
  const opened = useSignal(edit.opened)
  const form = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const saveLabel = useSignal(edit.saveLabel)
  const live = useSignal(edit.live)
  const action = useTrackedAction()
  const deletion = useTrackedAction()
  const { locked } = useLookOnly()
  const editBody = useRef<HTMLDivElement>(null)
  const editDelete = useRef<HTMLButtonElement>(null)
  const editSave = useRef<HTMLButtonElement>(null)
  const editCancel = useRef<HTMLButtonElement>(null)
  const deleteCancel = useRef<HTMLButtonElement>(null)
  const deleteConfirm = useRef<HTMLButtonElement>(null)
  // Read the confirmation phase synchronously in event handlers. A second
  // event can arrive before the tracked runner's busy state reaches the view.
  const [confirmation] = useState(() =>
    createSignal<'closed' | 'open' | 'deleting' | 'refused'>('closed'),
  )
  const phase = useSignal(confirmation)
  const deleteOpen = phase !== 'closed'
  const deleting = phase === 'deleting'
  const empty = live?.productCount === 0
  const removed = live === undefined
  const hasStock = !removed && !empty
  const [editAvailability, setEditAvailability] = useState({
    empty,
    saveAvailable,
  })

  // The session object is reused across openings. Only this mounted opening
  // may close it when the delete reply arrives.
  useLayoutEffect(() => () => confirmation.set('closed'), [confirmation])

  useLayoutEffect(() => {
    const root = editBody.current
    if (!root) return
    return registerFocusReturnFallback(root, () =>
      root.querySelector<HTMLElement>('[data-autofocus]'),
    )
  }, [opened])

  // Apply native disabling before paint, after a focused action has had a
  // chance to hand off focus. ARIA marks the live verdict for the closing trap.
  useLayoutEffect(() => {
    if (
      editAvailability.empty === empty &&
      editAvailability.saveAvailable === saveAvailable
    )
      return
    const root = editBody.current
    const focused = root?.ownerDocument.activeElement
    const dialog = root?.closest<HTMLElement>('[role="dialog"]')
    if (
      dialog &&
      focused &&
      !deleteOpen &&
      ((!empty && focused === editDelete.current) ||
        (!saveAvailable && focused === editSave.current))
    ) {
      focusInitial(dialog)
    }
    setEditAvailability({ empty, saveAvailable })
  }, [deleteOpen, editAvailability, empty, saveAvailable])

  // Once dispatched, the tracked reply owns the outcome, even if its row
  // removal reaches this window first.
  useLayoutEffect(() => {
    if (live === undefined && confirmation.get() === 'open') {
      confirmation.set('closed')
    }
  }, [confirmation, live, phase])

  // ARIA-disable the submit button so native focus survives the stock change.
  // Move it to Cancel before Tab can leave the top dialog from the old target.
  useLayoutEffect(() => {
    const cancel = deleteCancel.current
    if (
      !empty &&
      !deleting &&
      cancel &&
      cancel.ownerDocument.activeElement === deleteConfirm.current
    ) {
      cancel.focus({ preventScroll: true })
    }
  }, [empty, deleting])

  // Save and Enter go through the session's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with what was typed.
  async function submit(): Promise<void> {
    if (locked || confirmation.get() !== 'closed' || !edit.canSave.get()) return
    parkClosingControl(editCancel.current)
    await edit.save(action.run)
  }

  function closeEdit(): void {
    if (confirmation.get() === 'closed' && !edit.saving.get()) edit.close()
  }

  function openDelete(event: MouseEvent<HTMLButtonElement>): void {
    if (
      locked ||
      confirmation.get() !== 'closed' ||
      !edit.opened.get() ||
      edit.saving.get() ||
      edit.live.get()?.productCount !== 0
    )
      return
    event.currentTarget.focus({ preventScroll: true })
    confirmation.set('open')
  }

  function closeDelete(): void {
    const current = confirmation.get()
    if (current !== 'open' && current !== 'refused') return
    deletion.clearError()
    confirmation.set('closed')
  }

  async function submitDelete(): Promise<void> {
    const current = confirmation.get()
    if (
      locked ||
      (current !== 'open' && current !== 'refused') ||
      !edit.opened.get() ||
      edit.saving.get()
    )
      return
    const row = edit.live.get()
    if (row?.productCount !== 0) return

    parkClosingControl(deleteCancel.current)
    confirmation.set('deleting')
    try {
      const deleted = await deletion.run(sendWarehouseDelete(row.id))
      if (deleted && confirmation.get() === 'deleting' && edit.opened.get()) {
        confirmation.set('closed')
        edit.close()
      }
    } finally {
      if (confirmation.get() === 'deleting') confirmation.set('refused')
    }
  }

  return (
    <>
      <HilosModal
        open={opened}
        initialFocus="inner"
        onClose={closeEdit}
        confirmOnClose={state.dirty}
        busy={saving || deleting}
        header={
          <ConflictHeader title="Edit warehouse" conflict={state.conflict} />
        }
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={state.conflict}
            onAcceptMine={edit.keepMine}
            onAcceptTheirs={edit.takeTheirs}
            cancelButton={
              <button
                ref={editCancel}
                type="button"
                className="btn btn-secondary"
                disabled={saving || deleteOpen}
                aria-describedby={hasStock ? EDIT_STOCK_REASON_ID : undefined}
                data-id="admin-warehouses-edit-cancel"
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            // A submit button of the form: with two fields, Enter submits only
            // through one. The session's verdict includes conflict; keep the
            // native attribute staged even while the live choices are shown.
            saveButton={() => (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form={FORM_ID}
                loading={action.busy}
                disabled={!editAvailability.saveAvailable || deleteOpen}
                aria-disabled={!saveAvailable || deleteOpen || undefined}
                data-id="admin-warehouses-edit-submit"
                onFocus={(event) => {
                  editSave.current = event.currentTarget
                }}
              >
                {saveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <div ref={editBody} data-id="admin-warehouses-edit">
          <div data-id="admin-warehouses-edit-error">
            <HilosActionError
              action={action}
              detailsTitle="Couldn't save the warehouse"
            />
          </div>
          <form
            id={FORM_ID}
            inert={deleteOpen}
            onSubmit={(event) => {
              event.preventDefault()
              void submit()
            }}
          >
            <WarehouseFormFields
              idPrefix="admin-warehouses-edit"
              draft={form}
              onChange={edit.patchForm}
            />
            <HilosEditNotice
              kind={state.notice?.kind ?? null}
              text={noticeText}
              dataId="admin-warehouses-edit-notice"
            />
          </form>
          <div className="mt-3">
            <LoadingButton
              className="btn-outline-danger"
              type="button"
              disabled={!editAvailability.empty || saving}
              aria-disabled={!empty || deleteOpen || undefined}
              aria-describedby={hasStock ? EDIT_STOCK_REASON_ID : undefined}
              data-id="admin-warehouses-edit-delete"
              onFocus={(event) => {
                editDelete.current = event.currentTarget
              }}
              onClick={openDelete}
            >
              Delete warehouse
            </LoadingButton>
            <WarehouseStockReason
              id={EDIT_STOCK_REASON_ID}
              visible={hasStock}
              announce={hasStock && !deleteOpen}
            />
          </div>
        </div>
      </HilosModal>
      <HilosModal
        open={opened && deleteOpen}
        title="Delete warehouse?"
        busy={deleting}
        initialFocus="dialog"
        onClose={closeDelete}
        actions={({ requestClose }) => (
          <>
            <button
              ref={deleteCancel}
              type="button"
              className="btn btn-secondary"
              disabled={deleting}
              aria-describedby={hasStock ? DELETE_STOCK_REASON_ID : undefined}
              data-id="admin-warehouses-delete-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className={`btn-danger${!empty && !deleting ? ' opacity-50' : ''}`}
              loading={deleting}
              disabled={saving}
              aria-disabled={!empty || undefined}
              tabIndex={empty ? undefined : -1}
              aria-describedby={hasStock ? DELETE_STOCK_REASON_ID : undefined}
              data-id="admin-warehouses-delete-confirm"
              onFocus={(event) => {
                deleteConfirm.current = event.currentTarget
              }}
              onClick={() => void submitDelete()}
            >
              Delete warehouse
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={deletion}
          detailsTitle="Couldn't delete the warehouse"
        />
        <p className="mb-0">
          Permanently delete{' '}
          <strong className="text-break">
            {live?.name ?? 'this warehouse'}
          </strong>
          ?
        </p>
        <WarehouseStockReason id={DELETE_STOCK_REASON_ID} visible={hasStock} />
      </HilosModal>
    </>
  )
}
