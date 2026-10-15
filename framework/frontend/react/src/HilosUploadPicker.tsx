// HilosUploadPicker — the paperclip door of the upload field: a button that
// opens the browser's file chooser over a hidden input. The chosen files go to
// the field, and the input is emptied at once, or the same file could not be
// chosen twice in a row. The accept filter is a hint to the chooser only: a drop
// or a paste passes it by, and the server decides.
import {
  HILOS_UPLOAD_FIELD_COPY as COPY,
  type HilosUploadField,
} from '@hilos/core'
import { useRef } from 'react'
import type { ChangeEvent } from 'react'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosUploadPicker}. */
export interface HilosUploadPickerProps {
  /** The upload field the page created; the page disposes it, not this tag. */
  readonly field: HilosUploadField
  /** The data-id of the button; the hidden input derives its own from it. */
  readonly dataId: string
  /** The chooser's file filter, as the input's accept attribute; empty takes any file. */
  readonly accept?: string
}

/**
 * Draw the paperclip that chooses files for an upload field.
 *
 * @param props The field, the data-id and the chooser's filter.
 */
export function HilosUploadPicker({
  field,
  dataId,
  accept = '',
}: HilosUploadPickerProps) {
  const disabled = useSignal(field.disabled)
  const input = useRef<HTMLInputElement>(null)

  function picked(event: ChangeEvent<HTMLInputElement>): void {
    field.add(event.target.files ?? [])
    event.target.value = ''
  }

  return (
    <>
      <button
        type="button"
        className="btn btn-outline-secondary flex-shrink-0"
        aria-label={COPY.attach}
        disabled={disabled}
        data-id={dataId}
        onClick={() => input.current?.click()}
      >
        <i className="bi bi-paperclip" aria-hidden="true"></i>
      </button>
      <input
        ref={input}
        type="file"
        multiple
        className="d-none"
        accept={accept}
        data-id={`${dataId}-input`}
        onChange={picked}
      />
    </>
  )
}
