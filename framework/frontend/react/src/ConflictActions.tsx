// ConflictActions — the action group for an edit modal with 3-way-merge
// conflict resolution. The group draws, left to right, the conflict choices, the
// window's Cancel (`cancelButton`) and Save, in that same markup order, so Tab
// runs left to right. While no conflict stands, an invisible twin of the same
// markup holds the choices' room, so neither the arrival nor the leaving of a
// conflict moves Cancel or Save (styling-rules.md, "The room a live message
// takes"; the owner's decision on the acceptance of HIL-1050). Renders a Save
// button (pass `saveButton` to supply a custom one, e.g. a LoadingButton; it
// receives the computed `disabled` and an `onSave` handler) and, only while a
// conflict is unresolved, the three resolution choices: keep mine, take theirs,
// and merge where the surface asks for it. Save stays disabled until the
// conflict is resolved. The handlers fire the choice; the parent form applies
// it against the core threeWayMerge result. Bootstrap classes only.
import type { ReactNode } from 'react'

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
const CHOICE_CLASS = 'btn btn-outline-secondary'

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
  const disabled = disableSave || conflict
  const handleSave = (): void => onSave?.()

  return (
    <div className="hilos-button-group d-md-flex align-items-center gap-2 flex-wrap">
      {conflict ? (
        <div
          className="hilos-conflict-choices d-flex gap-2"
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
          className="hilos-conflict-choices d-flex gap-2 invisible"
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
          data-id="conflict-save"
          onClick={handleSave}
        >
          {saveLabel}
        </button>
      )}
    </div>
  )
}
