// ConflictActions — the action row for an edit modal with 3-way-merge conflict
// resolution. Renders a Save button (pass `saveButton` to supply a custom one,
// e.g. a LoadingButton; it receives the computed `disabled` and an `onSave`
// handler) and, only while a conflict is unresolved, the three resolution
// choices: keep mine, take theirs, or merge. Save stays disabled until the
// conflict is resolved. The handlers fire the choice; the parent form applies it
// against the core threeWayMerge result. Bootstrap classes only.
import type { ReactNode } from 'react'

/** Props for {@link ConflictActions}. */
export interface ConflictActionsProps {
  /** Whether an unresolved conflict blocks saving. */
  conflict?: boolean
  /** Disable save independently (e.g. an invalid draft). */
  disableSave?: boolean
  /** Save button label. */
  saveLabel?: string
  /** Save the resolved draft. */
  onSave?: () => void
  /** Resolve a conflict by keeping the user's draft. */
  onAcceptMine?: () => void
  /** Resolve a conflict by taking the incoming value. */
  onAcceptTheirs?: () => void
  /** Resolve a conflict by merging. */
  onMerge?: () => void
  /** Replace the default Save button (e.g. with a LoadingButton). */
  saveButton?: (args: { disabled: boolean; onSave: () => void }) => ReactNode
}

/**
 * The Save-plus-resolutions action row for a conflict-aware edit modal.
 *
 * @param props The conflict state, labels, choice handlers, and optional custom
 *   Save button.
 */
export function ConflictActions({
  conflict = false,
  disableSave = false,
  saveLabel = 'Save',
  onSave,
  onAcceptMine,
  onAcceptTheirs,
  onMerge,
  saveButton,
}: ConflictActionsProps) {
  const disabled = disableSave || conflict
  const handleSave = (): void => onSave?.()

  return (
    <div className="d-flex align-items-center gap-2 flex-wrap">
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
      {conflict ? (
        <>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            data-id="conflict-accept-mine"
            onClick={() => onAcceptMine?.()}
          >
            Keep mine
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            data-id="conflict-accept-theirs"
            onClick={() => onAcceptTheirs?.()}
          >
            Take theirs
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            data-id="conflict-merge"
            onClick={() => onMerge?.()}
          >
            Merge
          </button>
        </>
      ) : null}
    </div>
  )
}
