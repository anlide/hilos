import {
  useCallback,
  useLayoutEffect,
  useRef,
  useState,
  type MouseEvent,
} from 'react'
import {
  createSignal,
  focusInitial,
  registerFocusReturnFallback,
  type HilosUploadField,
} from '@hilos/core/base'
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  HilosUploadDrop,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { sendCategoryDelete } from './adminCategoriesActions.js'
import {
  canDeleteCategoryEdit,
  canSubmitCategoryEdit,
  type CategoryEdit,
} from './categoryEdit.js'
import { CategoryFormFields } from './CategoryFormFields.js'
import { useCatalogPictureField } from './CategoryPictureField.js'

const TITLE = 'Edit category'
const ID_PREFIX = 'admin-categories-edit'
const FORM_ID = 'admin-categories-edit-form'
const EDIT_CANCEL_ID = 'admin-categories-edit-cancel'
const EDIT_DELETE_ID = 'admin-categories-edit-delete'
const SAVE_ID = 'admin-categories-edit-submit'
const DELETE_CANCEL_ID = 'admin-categories-delete-cancel'
const CONFIRM_ID = 'admin-categories-delete-confirm'
const EDIT_PRODUCTS_REASON_ID = 'admin-categories-edit-products-reason'
const DELETE_PRODUCTS_REASON_ID = 'admin-categories-delete-products-reason'
const PRODUCTS_REASON = 'This category has products and cannot be deleted'

interface DialogButton {
  readonly dialog: HTMLElement
  readonly button: HTMLButtonElement
}

/** Read the focused button across native disable or removal in this dialog. */
function buttonLosingFocus(
  dialog: HTMLElement,
  focused: DialogButton | null,
): HTMLButtonElement | null {
  if (focused?.dialog !== dialog) return null
  const { button } = focused
  const { activeElement, body } = dialog.ownerDocument
  if (
    activeElement === button ||
    (activeElement === body &&
      (!button.isConnected || button.matches(':disabled')))
  ) {
    return button
  }

  return null
}

/** Park focus on the dialog before its Close and Cancel become disabled. */
function parkClosingButton(dialog: HTMLElement | null, cancelId: string): void {
  const active = dialog?.ownerDocument.activeElement
  const id = active?.getAttribute('data-id')
  if (
    dialog &&
    active?.closest('[role="dialog"]') === dialog &&
    (id === 'modal-close' || id === cancelId)
  ) {
    dialog.focus({ preventScroll: true })
  }
}

/** Reserve the reason's space and announce it in the active dialog. */
function CategoryProductsReason({
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
      <p
        id={id}
        className={`mb-0 mt-2 small text-body-secondary${visible ? '' : ' invisible'}`}
        aria-hidden={!visible}
      >
        {PRODUCTS_REASON}
      </p>
      <span className="visually-hidden" role="status" aria-live="polite">
        {announce ? PRODUCTS_REASON : ''}
      </span>
    </>
  )
}

/**
 * One opening of Edit category, with its own picture upload field.
 *
 * @param props The session over the focused category row.
 */
export function CategoryEditModal({ edit }: { edit: CategoryEdit }) {
  const field = useCatalogPictureField()

  return field !== null ? (
    <CategoryEditDialog edit={edit} field={field} />
  ) : null
}

/**
 * The edit form over the upload field of its opening.
 *
 * @param props The row-edit session and the dialog's upload field.
 */
function CategoryEditDialog({
  edit,
  field,
}: {
  edit: CategoryEdit
  field: HilosUploadField
}) {
  const opened = useSignal(edit.opened)
  const draft = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const canSave = useSignal(edit.canSave)
  const saveLabel = useSignal(edit.saveLabel)
  const live = useSignal(edit.live)
  // Render from the upload signals and read them again in each submit handler.
  useSignal(field.busy)
  useSignal(field.ready)
  const uploadId = useSignal(field.files)[0]?.clientUploadId
  const { locked } = useLookOnly()
  const publish = useTrackedAction()
  const save = useTrackedAction()
  const deletion = useTrackedAction()
  const [pending, setPending] = useState(false)
  const [lastRun, setLastRun] = useState<'publish' | 'save'>('save')
  const selectedUpload = useRef<string | null>(null)
  const editBody = useRef<HTMLDivElement>(null)
  const focusedButton = useRef<DialogButton | null>(null)
  const deleteCancel = useRef<HTMLButtonElement>(null)
  // Event handlers see the phase before React renders the new button state.
  const [confirmation] = useState(() =>
    createSignal<'closed' | 'open' | 'deleting' | 'refused'>('closed'),
  )
  const phase = useSignal(confirmation)
  const deleteOpen = phase !== 'closed'
  const deleting = phase === 'deleting'
  const picturePending = pending || publish.busy
  const canDelete = getDeleteTarget() !== undefined
  const disableDelete = !canDelete
  const disableConfirm = disableDelete && !deleting
  const hasProducts = live !== undefined && live.productCount > 0
  const onPublish = useCallback(() => setLastRun('publish'), [])
  const disableSave = deleteOpen || (!saving && !canSubmit(canSave))
  const savingWithConflict = saving && state.conflict
  const [saveDisabled, setSaveDisabled] = useState(disableSave)
  const [deleteDisabled, setDeleteDisabled] = useState(disableDelete)

  // The edit session is reused, but a delete reply belongs to this opening.
  useLayoutEffect(() => () => confirmation.set('closed'), [confirmation])

  useLayoutEffect(() => {
    const root = editBody.current
    if (!root) return
    const document = root.ownerDocument
    const editDialog = root.closest('[role="dialog"]')
    const stopFallback = registerFocusReturnFallback(root, () =>
      root.querySelector<HTMLElement>('[data-autofocus]'),
    )

    // Keep the button's owner even if React removes the chip. Focus anywhere
    // else, including in Details or Discard, clears the previous button.
    function rememberFocus(event: FocusEvent): void {
      const button = event.target
      if (button === document.body && focusedButton.current) {
        const previous = focusedButton.current.button
        if (!previous.isConnected || previous.matches(':disabled')) return
      }
      if (!(button instanceof HTMLButtonElement)) {
        focusedButton.current = null
        return
      }
      const dialog = button.closest<HTMLElement>('[role="dialog"]')
      const deleteDialog = deleteCancel.current?.closest('[role="dialog"]')
      focusedButton.current =
        dialog && (dialog === editDialog || dialog === deleteDialog)
          ? { button, dialog }
          : null
    }
    document.addEventListener('focusin', rememberFocus, true)
    return () => {
      document.removeEventListener('focusin', rememberFocus, true)
      focusedButton.current = null
      stopFallback()
    }
  }, [opened])

  // Reconcile chip removal and LoadingButton's native look-only lock, then
  // apply the local disabled states. A loading Save with live conflict choices
  // also hands off to the dialog so Tab starts from inside the available set.
  useLayoutEffect(() => {
    const dialog = editBody.current?.closest<HTMLElement>('[role="dialog"]')
    if (dialog && !deleteOpen) {
      const button = buttonLosingFocus(dialog, focusedButton.current)
      const id = button?.dataset.id
      const remove = id === `${ID_PREFIX}-upload-file-remove`
      const uploadDetails = id === `${ID_PREFIX}-upload-error-details`
      if (
        (id === SAVE_ID && (disableSave || locked || savingWithConflict)) ||
        (id === EDIT_DELETE_ID && disableDelete) ||
        (locked && !saving && (id === `${ID_PREFIX}-picture` || remove)) ||
        ((remove || uploadDetails) && !button?.isConnected)
      ) {
        focusInitial(dialog, saving ? 'dialog' : 'marked')
      }
    }
    field.setDisabled(locked || saving || deleteOpen)
    setSaveDisabled(disableSave)
    setDeleteDisabled(disableDelete)
  }, [
    deleteOpen,
    disableDelete,
    disableSave,
    field,
    locked,
    saving,
    savingWithConflict,
    uploadId,
  ])

  // A removal after dispatch is left to the tracked reply.
  useLayoutEffect(() => {
    if (live === undefined && confirmation.get() === 'open') {
      confirmation.set('closed')
    }
  }, [confirmation, live, phase])

  useLayoutEffect(() => {
    const cancel = deleteCancel.current
    const dialog = cancel?.closest<HTMLElement>('[role="dialog"]')
    if (
      cancel &&
      dialog &&
      (disableConfirm || locked) &&
      buttonLosingFocus(dialog, focusedButton.current)?.dataset.id ===
        CONFIRM_ID
    ) {
      if (deleting) {
        dialog.focus({ preventScroll: true })
      } else {
        cancel.focus({ preventScroll: true })
      }
    }
  }, [deleting, disableConfirm, locked])

  function readWriteState() {
    return {
      locked,
      picturePending,
      uploadBusy: field.busy.get(),
      selectedUploadId: selectedUpload.current,
      readyUploads: field.ready.get(),
    }
  }

  function canSubmit(sessionCanSave = edit.canSave.get()): boolean {
    return canSubmitCategoryEdit({
      ...readWriteState(),
      canSave: sessionCanSave,
    })
  }

  function getDeleteTarget() {
    const row = edit.live.get()
    return canDeleteCategoryEdit({
      ...readWriteState(),
      opened: edit.opened.get(),
      productCount: row?.productCount,
      saving: edit.saving.get(),
      deleting: confirmation.get() === 'deleting',
    })
      ? row
      : undefined
  }

  async function submit(): Promise<void> {
    if (confirmation.get() !== 'closed' || !canSubmit()) return
    parkClosingButton(
      editBody.current?.closest<HTMLElement>('[role="dialog"]') ?? null,
      EDIT_CANCEL_ID,
    )
    setLastRun('save')
    await edit.save(save.run)
  }

  function takeTheirs(): void {
    const uploadId = selectedUpload.current
    if (edit.state.get().fields.pictureFileId?.conflict && uploadId !== null) {
      // The publication can still answer after the choice discarded its draft.
      selectedUpload.current = null
      field.discard(uploadId)
    }
    edit.takeTheirs()
  }

  function close(): void {
    if (confirmation.get() !== 'closed' || edit.saving.get()) return
    selectedUpload.current = null
    edit.close()
  }

  function openDelete(event: MouseEvent<HTMLButtonElement>): void {
    if (confirmation.get() !== 'closed' || getDeleteTarget() === undefined)
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
    if (current !== 'open' && current !== 'refused') return
    const row = getDeleteTarget()
    if (row === undefined) return

    parkClosingButton(
      deleteCancel.current?.closest<HTMLElement>('[role="dialog"]') ?? null,
      DELETE_CANCEL_ID,
    )
    confirmation.set('deleting')
    try {
      const deleted = await deletion.run(sendCategoryDelete(row.id))
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
        title={TITLE}
        initialFocus="inner"
        onClose={close}
        confirmOnClose={state.dirty || picturePending}
        busy={saving || deleting}
        header={<ConflictHeader title={TITLE} conflict={state.conflict} />}
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={state.conflict}
            disableSave={disableSave}
            onAcceptMine={edit.keepMine}
            onAcceptTheirs={takeTheirs}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={saving || deleteOpen}
                aria-describedby={
                  hasProducts ? EDIT_PRODUCTS_REASON_ID : undefined
                }
                data-id={EDIT_CANCEL_ID}
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled }) => (
              <LoadingButton
                className="btn-primary"
                type="submit"
                form={FORM_ID}
                loading={saving}
                disabled={saveDisabled || deleteOpen}
                aria-disabled={disabled || undefined}
                tabIndex={savingWithConflict ? -1 : undefined}
                data-id={SAVE_ID}
                onPointerDown={(event) => {
                  if (savingWithConflict) event.preventDefault()
                }}
              >
                {saveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosUploadDrop field={field} dataId="admin-categories-edit-drop">
          <div ref={editBody} data-id="admin-categories-edit">
            <div data-id="admin-categories-edit-error">
              <HilosActionError
                action={lastRun === 'publish' ? publish : save}
                detailsTitle="Couldn't save the category"
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
              <CategoryFormFields
                idPrefix={ID_PREFIX}
                draft={draft}
                onChange={edit.patchForm}
                pictureField={{
                  field,
                  publish,
                  onPublish,
                  onSelection: (id) => {
                    selectedUpload.current = id
                  },
                  onPicture: (picture, clientUploadId) => {
                    if (selectedUpload.current === clientUploadId) {
                      selectedUpload.current = null
                      edit.patchForm({ picture })
                    }
                  },
                  onPending: setPending,
                }}
              />
              <HilosEditNotice
                kind={state.notice?.kind ?? null}
                text={noticeText}
                dataId="admin-categories-edit-notice"
              />
            </form>
            <div className="mt-3">
              <LoadingButton
                className="btn-outline-danger"
                type="button"
                disabled={deleteDisabled}
                aria-disabled={!canDelete || deleteOpen || undefined}
                aria-describedby={
                  hasProducts ? EDIT_PRODUCTS_REASON_ID : undefined
                }
                data-id={EDIT_DELETE_ID}
                onClick={openDelete}
              >
                Delete category
              </LoadingButton>
              <CategoryProductsReason
                id={EDIT_PRODUCTS_REASON_ID}
                visible={hasProducts}
                announce={hasProducts && !deleteOpen}
              />
            </div>
          </div>
        </HilosUploadDrop>
      </HilosModal>
      <HilosModal
        open={opened && deleteOpen}
        title="Delete category?"
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
              aria-describedby={
                hasProducts ? DELETE_PRODUCTS_REASON_ID : undefined
              }
              data-id={DELETE_CANCEL_ID}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className={`btn-danger${disableConfirm ? ' opacity-50' : ''}`}
              loading={deleting}
              aria-disabled={!canDelete || undefined}
              tabIndex={disableConfirm ? -1 : undefined}
              aria-describedby={
                hasProducts ? DELETE_PRODUCTS_REASON_ID : undefined
              }
              data-id={CONFIRM_ID}
              onPointerDown={(event) => {
                if (disableConfirm) event.preventDefault()
              }}
              onClick={() => void submitDelete()}
            >
              Delete category
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={deletion}
          detailsTitle="Couldn't delete the category"
        />
        <p className="mb-0">
          Permanently delete{' '}
          <strong className="text-break">
            {live?.name ?? 'this category'}
          </strong>
          ?
        </p>
        <CategoryProductsReason
          id={DELETE_PRODUCTS_REASON_ID}
          visible={hasProducts}
        />
      </HilosModal>
    </>
  )
}
