// HilosStatusWorkModal — the one window for a scheduled maintenance announcement:
// Schedule maintenance over the core creation controller and Change scheduled
// maintenance over the core row-edit window. React counterpart of the Vue modal:
// a new announcement and a live row edit share the form, never the merge. The
// page-owned controller keeps the draft, the captured reader zone, the verdict
// and the tracked action; the row edit adds the standard per-field merge with
// Keep mine / Take theirs. This view mirrors native values and draws that state;
// validation and calendar interpretation stay in core. The window keeps its
// draft on a refusal, an unconfirmed outcome or a target that ended, and closes
// on the server's answer alone. Bootstrap classes only.
import { useId } from 'react'
import type { KeyboardEvent } from 'react'
import {
  createSignal,
  formatMoment,
  hilosTimeZone,
  hilosToasts,
  type ReadonlySignal,
  type RowEditState,
} from '@hilos/core/base'
import type {
  HilosStatusWorkCreate,
  HilosStatusWorkEdit,
  HilosStatusWorkEditFields,
  HilosStatusWorkForm,
  HilosStatusWorksState,
} from '@hilos/core/admin/status'

import { ConflictActions } from '../../ConflictActions.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosStatusWorkModal}. */
export interface HilosStatusWorkModalProps {
  /** A real core creation or row-edit window, owned by the Works page. */
  controller: HilosStatusWorkCreate | HilosStatusWorkEdit
  /** The component catalog from the complete first page answer. */
  state: HilosStatusWorksState
}

/** A creation has no row merge: no merge verdict to read. */
const NO_EDIT_STATE: ReadonlySignal<RowEditState<HilosStatusWorkEditFields> | null> =
  createSignal(null)

/** A creation has no row merge: nothing to say about the other side. */
const NO_EDIT_NOTICE: ReadonlySignal<string> = createSignal('')

/** A creation's Save button keeps its one word. */
const SCHEDULE_LABEL: ReadonlySignal<string> = createSignal(
  'Schedule maintenance',
)

/** The names the conflict notice gives the edited fields. */
const FIELD_LABELS: Record<keyof HilosStatusWorkForm, string> = {
  title: 'Title',
  description: 'What visitors read',
  componentKeys: 'Affects',
  startsAt: 'Starts',
  endsAt: 'Ends',
}

/**
 * The signals of the window's own kind: the row edit's merge, or the creation's
 * stand-ins. Every source is the controller's own or a constant, so a render
 * reads the same sources until the page hands over another controller.
 *
 * @param controller The page-owned creation or row-edit window.
 */
function windowSignals(
  controller: HilosStatusWorkCreate | HilosStatusWorkEdit,
) {
  if ('state' in controller) {
    return {
      editor: controller,
      busy: controller.saving,
      editState: controller.state,
      editNoticeText: controller.noticeText,
      saveAvailable: controller.saveAvailable,
      saveLabel: controller.saveLabel,
      canSubmit: controller.canSave,
    }
  }
  return {
    editor: null,
    busy: controller.busy,
    editState: NO_EDIT_STATE,
    editNoticeText: NO_EDIT_NOTICE,
    saveAvailable: controller.submitAvailable,
    saveLabel: SCHEDULE_LABEL,
    canSubmit: controller.canSubmit,
  }
}

/**
 * Schedule a new announcement, or change the one a row edit opened on.
 *
 * @param props The page-owned window and the Works page's catalog state.
 */
export function HilosStatusWorkModal({
  controller,
  state,
}: HilosStatusWorkModalProps) {
  const signals = windowSignals(controller)
  const { editor } = signals
  const opened = useSignal(controller.opened)
  const form = useSignal(controller.form)
  const dirty = useSignal(controller.dirty)
  const timeZone = useSignal(controller.timeZone)
  const readerTimeZone = useSignal(hilosTimeZone)
  const busy = useSignal(signals.busy)
  const error = useSignal(controller.error)
  const failure = useSignal(controller.failure)
  const blockedReason = useSignal(controller.blockedReason)
  const data = useSignal(state.data)
  const editState = useSignal(signals.editState)
  const editNoticeText = useSignal(signals.editNoticeText)
  const saveAvailable = useSignal(signals.saveAvailable)
  const saveLabel = useSignal(signals.saveLabel)
  const title = editor ? 'Change scheduled maintenance' : 'Schedule maintenance'
  const formId = useId()
  const hintId = useId()
  const overlapHintId = useId()
  const fieldsId = useId()
  const copyText = failure?.errorDetail
    ? [failure.errorType, failure.errorDetail].filter(Boolean).join('\n')
    : (error ?? '')
  const noticeKind = blockedReason
    ? 'deleted'
    : (editState?.notice?.kind ?? null)

  /**
   * The names of the catalog's components, in the order of the keys.
   *
   * @param keys The component keys to name.
   */
  function componentNames(keys: readonly string[]): string {
    return keys
      .map(
        (key) =>
          data?.components.find((component) => component.componentKey === key)
            ?.name ?? key,
      )
      .join(', ')
  }

  /** The blocked reason, or the merge's word with both sides of each conflict. */
  function noticeText(): string {
    if (blockedReason) return blockedReason
    if (!editState?.conflict) return editNoticeText
    const values = editState.fields
    const conflicts = editState.notice?.fields ?? []
    return [
      editNoticeText,
      ...conflicts.map((field) => {
        const incoming = values[field].incoming
        const momentField = field === 'startsAt' || field === 'endsAt'
        const theirs = momentField
          ? incoming === null
            ? '—'
            : `${formatMoment(incoming as number) ?? '—'} (${readerTimeZone})`
          : field === 'componentKeys'
            ? componentNames(JSON.parse(incoming as string) as string[])
            : (incoming ?? '')
        const mine =
          field === 'componentKeys'
            ? componentNames(form.componentKeys)
            : momentField
              ? `${form[field]} (${timeZone})`
              : form[field]
        return `${FIELD_LABELS[field]}: Keep mine: ${mine}; Take theirs: ${theirs}`
      }),
    ].join('\n')
  }

  /**
   * Mirror native values; validation and calendar interpretation stay in core.
   *
   * @param patch The fields the person changed.
   */
  function patch(patch: Partial<HilosStatusWorkForm>): void {
    controller.patchForm(patch)
  }

  /**
   * Add a component to the announcement or take it away.
   *
   * @param key The component's key.
   * @param checked Whether its box is now checked.
   */
  function toggleComponent(key: string, checked: boolean): void {
    patch({
      componentKeys: checked
        ? [...form.componentKeys, key]
        : form.componentKeys.filter((value) => value !== key),
    })
  }

  /** Enter and the footer use the same verdict; success belongs to this request. */
  async function submit(): Promise<void> {
    if (!signals.canSubmit.get()) return
    if (await controller.submit()) {
      const message = controller.success.get()
      if (message !== null) hilosToasts.push(message, { severity: 'success' })
    }
  }

  /**
   * Enter submits from a field; a textarea keeps its new line, and a button or
   * a link keeps its own press. A key pressed in a details window the form's
   * refusal or notice opened is not this form's: that window is a portal
   * outside the form's DOM, yet its key still bubbles up the React tree to here.
   *
   * @param event The key pressed inside the form.
   */
  function onKeyDown(event: KeyboardEvent<HTMLFormElement>): void {
    if (event.key !== 'Enter') return
    if (!event.currentTarget.contains(event.target as Node)) return
    if (
      event.target instanceof Element &&
      event.target.closest('textarea, button, a')
    )
      return
    event.preventDefault()
    void submit()
  }

  return (
    <HilosModal
      open={opened}
      title={title}
      busy={busy}
      confirmOnClose={dirty}
      initialFocus="inner"
      onClose={() => controller.close()}
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={editState?.conflict ?? false}
          disableSave={!busy && !saveAvailable}
          onSave={() => void submit()}
          onAcceptMine={() => editor?.keepMine()}
          onAcceptTheirs={() => editor?.takeTheirs()}
          cancelButton={
            <button
              type="button"
              className="btn btn-secondary"
              disabled={busy}
              data-id="hilos-status-work-close"
              onClick={requestClose}
            >
              Cancel
            </button>
          }
          saveButton={({ disabled }) => (
            <LoadingButton
              form={formId}
              type="submit"
              className="btn-primary"
              loading={busy}
              disabled={disabled}
              data-id="hilos-status-work-save"
            >
              {busy && editor ? 'Save' : saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      {opened && (
        <form
          id={formId}
          aria-label={title}
          aria-describedby={hintId}
          data-id="hilos-status-work-form"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
          onKeyDown={onKeyDown}
        >
          <HilosFormError
            message={error}
            errorType={failure?.errorType}
            errorDetail={failure?.errorDetail}
            detailsTitle={title}
            copyText={copyText}
            dataId="hilos-status-work-error"
            announce
          />
          <div className="mb-2">
            <label htmlFor={`${fieldsId}-title`} className="form-label">
              Title
            </label>
            <input
              id={`${fieldsId}-title`}
              value={form.title}
              className="form-control form-control-sm"
              data-id="hilos-status-work-title"
              data-autofocus
              onChange={(event) => patch({ title: event.target.value })}
            />
          </div>
          <div className="row g-2 mb-1">
            <div className="col-sm-6">
              <label htmlFor={`${fieldsId}-starts`} className="form-label">
                Starts, {timeZone}
              </label>
              <input
                id={`${fieldsId}-starts`}
                value={form.startsAt}
                type="datetime-local"
                step={1}
                className="form-control form-control-sm"
                aria-describedby={overlapHintId}
                data-id="hilos-status-work-starts"
                onChange={(event) => patch({ startsAt: event.target.value })}
              />
            </div>
            <div className="col-sm-6">
              <label htmlFor={`${fieldsId}-ends`} className="form-label">
                Ends, {timeZone}
              </label>
              <input
                id={`${fieldsId}-ends`}
                value={form.endsAt}
                type="datetime-local"
                step={1}
                className="form-control form-control-sm"
                aria-describedby={overlapHintId}
                data-id="hilos-status-work-ends"
                onChange={(event) => patch({ endsAt: event.target.value })}
              />
            </div>
          </div>
          <div id={overlapHintId} className="form-text mt-0 mb-2">
            The window cannot overlap another announcement, even if the
            components differ. Simultaneous work is one announcement with
            several components. Adjacent windows are fine: one ends at 07:00,
            the next starts at 07:00.
          </div>
          <fieldset className="mb-2">
            <legend className="form-label fs-6 mb-1">Affects</legend>
            {(data?.components ?? []).map((component) => (
              <div
                key={component.componentKey}
                className="form-check form-check-inline"
              >
                <input
                  id={`${fieldsId}-${component.componentKey}`}
                  checked={form.componentKeys.includes(component.componentKey)}
                  type="checkbox"
                  className="form-check-input"
                  data-id={`hilos-status-work-component-${component.componentKey}`}
                  onChange={(event) =>
                    toggleComponent(
                      component.componentKey,
                      event.target.checked,
                    )
                  }
                />
                <label
                  htmlFor={`${fieldsId}-${component.componentKey}`}
                  className="form-check-label"
                >
                  {component.name}
                </label>
              </div>
            ))}
          </fieldset>
          <div className="mb-2">
            <label htmlFor={`${fieldsId}-description`} className="form-label">
              What visitors read
            </label>
            <textarea
              id={`${fieldsId}-description`}
              value={form.description}
              className="form-control form-control-sm"
              rows={2}
              data-id="hilos-status-work-description"
              onChange={(event) => patch({ description: event.target.value })}
            />
          </div>
          <p id={hintId} className="small text-body-secondary mb-0">
            This is an advance announcement. It does not close the service; the
            freeze in Maintenance starts the actual work.
          </p>
          <HilosEditNotice
            kind={noticeKind}
            text={noticeText()}
            dataId="hilos-status-work-notice"
          />
        </form>
      )}
    </HilosModal>
  )
}
