// ConflictActions — the action group for an edit modal with 3-way-merge
// conflict resolution. The group draws, left to right, the conflict choices, the
// window's Cancel (`cancelButton`) and Save, in that same markup order, so Tab
// runs left to right. Below md the group runs top to bottom in that same
// markup order. While no conflict stands, an invisible twin of the same
// markup holds the choices' room, so neither the arrival nor the leaving of a
// conflict moves Cancel or Save (styling-rules.md, "The room a live message
// takes"; the owner's decision on the acceptance of HIL-1050). Renders a Save
// button (pass `saveButton` to supply a custom one, e.g. a LoadingButton; it
// receives the computed `disabled` and an `onSave` handler) and, only while a
// conflict is unresolved, the three resolution choices: keep mine, take theirs,
// and merge where the surface asks for it. Save stays disabled until the
// conflict is resolved. The handlers fire the choice; the parent form applies
// it against the core threeWayMerge result. Resolving a conflict removes its
// focused button, so the focus returns to the dialog's initial focus, unless it
// has deliberately moved to another control or modal. In the page's area of a
// takeover that only looks Save stands disabled — the default one and the
// custom one alike (HIL-1170); Cancel and the conflict choices, which edit only
// the draft in this window, stay as always. Bootstrap classes only.
import { useCallback, useLayoutEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import { focusInitial } from '@hilos/core/base'

import { useLookOnly } from './hilosLookOnly.js'

/** Props for {@link ConflictActions}. */
export interface ConflictActionsProps {
  /** Whether an unresolved conflict blocks saving. */
  conflict?: boolean
  /** Disable save independently (e.g. an invalid draft). */
  disableSave?: boolean
  /** Save button label. */
  saveLabel?: string
  /**
   * Whether the Merge resolution is offered. Only a surface that asks for it
   * shows the button — one where splicing the two values makes sense. A typed
   * value (a setting, a field, a name) never asks: the splice is not a value.
   * Defaults to false.
   */
  mergeable?: boolean
  /** Save the resolved draft. */
  onSave?: () => void
  /** Resolve a conflict by keeping the user's draft. */
  onAcceptMine?: () => void
  /** Resolve a conflict by taking the incoming value. */
  onAcceptTheirs?: () => void
  /** Resolve a conflict by merging. */
  onMerge?: () => void
  /**
   * The window's Cancel button, drawn between the conflict choices and Save.
   */
  cancelButton?: ReactNode
  /** Replace the default Save button (e.g. with a LoadingButton). */
  saveButton?: (args: { disabled: boolean; onSave: () => void }) => ReactNode
}

/**
 * The choice buttons and the inert spans of their twin, to the character —
 * the room equals the true width of the choices only while the classes match.
 */
const CHOICE_CLASS = 'btn btn-outline-secondary flex-fill'

/**
 * The Save-plus-resolutions action group for a conflict-aware edit modal.
 *
 * @param props The conflict state, labels, choice handlers, and optional custom
 *   Save button.
 */
export function ConflictActions({
  conflict = false,
  disableSave = false,
  saveLabel = 'Save',
  mergeable = false,
  onSave,
  onAcceptMine,
  onAcceptTheirs,
  onMerge,
  cancelButton,
  saveButton,
}: ConflictActionsProps) {
  const lookOnly = useLookOnly()
  const disabled = disableSave || conflict || lookOnly.locked
  const handleSave = (): void => onSave?.()
  const root = useRef<HTMLDivElement>(null)
  const leftDialog = useRef<HTMLElement | null>(null)

  // Resolving a conflict removes its focused button. Keep keyboard focus in the
  // same dialog, while preserving a deliberate move to another control or modal.
  // React lets go of the ref before it removes the block, so the cleanup still
  // sees whether the focus stood among the choices.
  const choices = useCallback((block: HTMLDivElement | null) => {
    if (block === null) return

    return () => {
      leftDialog.current = block.contains(block.ownerDocument.activeElement)
        ? block.closest<HTMLElement>('[role="dialog"]')
        : null
    }
  }, [])
  useLayoutEffect(() => {
    const dialog = leftDialog.current
    leftDialog.current = null
    if (conflict || dialog === null) return
    if (!root.current?.isConnected || !dialog.isConnected) return
    const active = dialog.ownerDocument.activeElement
    if (active === null || active === dialog.ownerDocument.body)
      focusInitial(dialog)
  }, [conflict])

  return (
    <div
      ref={root}
      className="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2 flex-wrap"
    >
      {/* Keyed apart, so the choices leave whole and their ref lets go first;
          unkeyed, React would turn them into the twin and drop the buttons. */}
      {conflict ? (
        <div
          key="choices"
          ref={choices}
          className="d-flex gap-2"
          data-id="conflict-choices"
        >
          <button
            type="button"
            className={CHOICE_CLASS}
            data-id="conflict-accept-mine"
            onClick={() => onAcceptMine?.()}
          >
            Keep mine
          </button>
          <button
            type="button"
            className={CHOICE_CLASS}
            data-id="conflict-accept-theirs"
            onClick={() => onAcceptTheirs?.()}
          >
            Take theirs
          </button>
          {mergeable ? (
            <button
              type="button"
              className={CHOICE_CLASS}
              data-id="conflict-merge"
              onClick={() => onMerge?.()}
            >
              Merge
            </button>
          ) : null}
        </div>
      ) : (
        <div
          key="idle"
          className="d-flex gap-2 invisible"
          aria-hidden="true"
          data-id="conflict-choices-idle"
        >
          {/* Spans, not buttons: the twin holds the room, it takes no focus. */}
          <span className={CHOICE_CLASS}>Keep mine</span>
          <span className={CHOICE_CLASS}>Take theirs</span>
          {mergeable ? <span className={CHOICE_CLASS}>Merge</span> : null}
        </div>
      )}
      {cancelButton}
      {saveButton ? (
        saveButton({ disabled, onSave: handleSave })
      ) : (
        <button
          type="button"
          className="btn btn-primary"
          disabled={disabled}
          aria-describedby={lookOnly.describedBy}
          data-id="conflict-save"
          onClick={handleSave}
        >
          {saveLabel}
        </button>
      )}
    </div>
  )
}
