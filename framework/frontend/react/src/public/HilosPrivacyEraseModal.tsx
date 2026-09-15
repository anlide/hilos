// HilosPrivacyEraseModal — the confirmation the erase on /privacy asks for: one
// question, two answers, no fields, the dangerous answer in red, the shape the
// component mockup draws for a destructive confirmation.
//
// Its body is the LIST of what goes, one line per registry entry, taken from each
// entry's own label — that is what makes the click worth asking for: the person
// agrees to a list rather than to an adjective, and the list is right by
// construction because it IS the registry. Under it the three sentences of the
// boundary: this device only, irreversible, and the account untouched.
//
// Not exported from the package. It is this page's own confirmation and has no
// second caller. Bootstrap classes only (styling-rules.md).
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'

/** Props for {@link HilosPrivacyEraseModal}. */
export interface HilosPrivacyEraseModalProps {
  /** Whether the confirmation is open (controlled). */
  open: boolean
  /** One line per registry entry: what this erase would take. */
  labels: readonly string[]
  /** The erase is in flight: the answers are held and the dialog cannot be dismissed. */
  busy: boolean
  /** Deferred loading of the erase, for the confirming button's spinner. */
  loading: boolean
  /** Called when the dialog is dismissed. */
  onClose: () => void
  /** Called when the dangerous answer is taken. */
  onConfirm: () => void
}

/**
 * The destructive confirmation of the erase block.
 *
 * @param props The open state, the list of what goes, the in-flight state, and
 *   the two answers.
 */
export function HilosPrivacyEraseModal({
  open,
  labels,
  busy,
  loading,
  onClose,
  onConfirm,
}: HilosPrivacyEraseModalProps) {
  return (
    <HilosModal
      open={open}
      title="Erase everything this browser keeps?"
      closeOnBackdrop={!busy}
      closeOnEsc={!busy}
      initialFocus="dialog"
      onClose={onClose}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={busy}
            data-id="privacy-erase-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          <LoadingButton
            className="btn-danger"
            loading={loading}
            data-id="privacy-erase-confirm"
            onClick={onConfirm}
          >
            Erase and sign out
          </LoadingButton>
        </>
      )}
    >
      <p className="mb-2">This will remove from this browser:</p>
      <ul className="mb-3 ps-3" data-id="privacy-erase-list">
        {labels.map((label) => (
          <li key={label} className="small">
            {label}
          </li>
        ))}
      </ul>
      <p className="mb-2 small text-body-secondary">
        Only this device is affected, and only this browser on it.
      </p>
      <p className="mb-2 small text-body-secondary">
        It cannot be undone: nothing here is kept anywhere to put back.
      </p>
      <p className="mb-0 small text-body-secondary">
        Your account and everything in it are untouched — this is not account
        deletion.
      </p>
    </HilosModal>
  )
}
