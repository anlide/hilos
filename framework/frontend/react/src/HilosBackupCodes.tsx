// HilosBackupCodes — a set of backup codes of the second factor shown once, the
// way the design asks (HIL-494, Design п.6): the list, a download of it as a text
// file, a copy of it, and "I have saved these codes", which the screen under it
// waits for. The codes are the only instant way back in when the app is lost, so
// the screen pushes the person to keep them rather than flash them past.
import {
  copyToClipboard,
  downloadTextFile,
  isClipboardAvailable,
} from '@hilos/core'
import { useId, useState } from 'react'

/** Props for {@link HilosBackupCodes}. */
export interface HilosBackupCodesProps {
  /** The codes, in display form. */
  codes: readonly string[]
  /** Whether "I have saved these codes" is ticked. */
  saved: boolean
  /** Called when the checkbox moves. */
  onSavedChange: (saved: boolean) => void
}

/** The name the downloaded list is offered under. */
const FILE_NAME = 'backup-codes.txt'

/**
 * Show a set of backup codes with the ways to keep them.
 *
 * @param props The component props.
 * @param props.codes The codes, in display form.
 * @param props.saved Whether "I have saved these codes" is ticked.
 * @param props.onSavedChange Called when the checkbox moves.
 */
export function HilosBackupCodes({
  codes,
  saved,
  onSavedChange,
}: HilosBackupCodesProps) {
  const savedId = useId()
  const [copied, setCopied] = useState(false)
  const canCopy = isClipboardAvailable()
  // The list as a file and as the clipboard hold it: one code a line.
  const text = `${codes.join('\n')}\n`

  return (
    <div data-id="backup-codes">
      <ul
        className="list-unstyled row row-cols-2 g-2 font-monospace mb-3"
        data-id="backup-codes-list"
      >
        {codes.map((code) => (
          <li key={code} className="col text-center">
            <span className="d-block border rounded py-1">{code}</span>
          </li>
        ))}
      </ul>
      <div className="d-flex gap-2 mb-3">
        <button
          type="button"
          className="btn btn-outline-secondary btn-sm flex-fill"
          data-id="backup-codes-download"
          onClick={() =>
            downloadTextFile(FILE_NAME, text, 'text/plain;charset=utf-8')
          }
        >
          <i className="bi bi-download me-1" aria-hidden="true" />
          Download
        </button>
        {canCopy ? (
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm flex-fill"
            data-id="backup-codes-copy"
            onClick={() => {
              void copyToClipboard(text).then(setCopied)
            }}
          >
            <i
              className={`bi ${copied ? 'bi-check2' : 'bi-clipboard'} me-1`}
              aria-hidden="true"
            />
            {copied ? 'Copied' : 'Copy'}
          </button>
        ) : null}
      </div>
      <div className="form-check mb-3">
        <input
          id={savedId}
          className="form-check-input"
          type="checkbox"
          data-id="backup-codes-saved"
          checked={saved}
          onChange={(event) => onSavedChange(event.target.checked)}
        />
        <label className="form-check-label small" htmlFor={savedId}>
          I have saved these codes
        </label>
      </div>
    </div>
  )
}
