// HilosUploadDrop — the wrapper of a project's own content that takes files
// dragged over it or pasted into it, and the voice of the upload field. The
// field (@hilos/core primitives/uploadField) owns the state; this tag only feeds
// it the two doors and draws what it says (multiframework-core.md,
// "Primitives").
// A drag that carries no files — selected text, a link — is not touched at all,
// and a paste without files goes on as an ordinary paste. A file drag is always
// taken, even while the field is disabled, so the browser never opens the file
// in place of the page; the field itself then refuses it.
// The voice lives here and not on the chips: the chips come and go, and a live
// region dies with its block, so the two permanent regions stand on the one
// surface that outlives them (accessibility.md, "The room belongs to the block,
// the voice to the surface").
import {
  HILOS_UPLOAD_FIELD_COPY as COPY,
  HILOS_UPLOAD_FIELD_OVERLAY_CLASS,
  type HilosUploadField,
} from '@hilos/core/base'
import type { ClipboardEvent, DragEvent, ReactNode } from 'react'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosUploadDrop}. */
export interface HilosUploadDropProps {
  /** The upload field the page created; the page disposes it, not this tag. */
  readonly field: HilosUploadField
  /** The data-id of the wrapper; the overlay and the two live regions derive theirs from it. */
  readonly dataId: string
  /** The project's own content the files are dropped or pasted onto. */
  readonly children?: ReactNode
}

function carriesFiles(event: DragEvent<HTMLDivElement>): boolean {
  return event.dataTransfer.types.includes('Files')
}

/**
 * Wrap the project's content as the drop and paste door of an upload field.
 *
 * @param props The field, the data-id and the wrapped content.
 */
export function HilosUploadDrop({
  field,
  dataId,
  children,
}: HilosUploadDropProps) {
  const dragging = useSignal(field.dragging)
  const alert = useSignal(field.alert)
  const status = useSignal(field.status)

  function enter(event: DragEvent<HTMLDivElement>): void {
    if (!carriesFiles(event)) {
      return
    }
    event.preventDefault()
    field.dragEnter()
  }

  function over(event: DragEvent<HTMLDivElement>): void {
    if (carriesFiles(event)) {
      event.preventDefault()
    }
  }

  function leave(event: DragEvent<HTMLDivElement>): void {
    if (!carriesFiles(event)) {
      return
    }
    event.preventDefault()
    field.dragLeave()
  }

  function drop(event: DragEvent<HTMLDivElement>): void {
    if (!carriesFiles(event)) {
      return
    }
    event.preventDefault()
    field.drop(event.dataTransfer.files)
  }

  function paste(event: ClipboardEvent<HTMLDivElement>): void {
    const files = event.clipboardData.files
    if (files.length === 0) {
      return
    }
    event.preventDefault()
    field.add(files)
  }

  return (
    <div
      className="position-relative"
      data-id={dataId}
      onDragEnter={enter}
      onDragOver={over}
      onDragLeave={leave}
      onDrop={drop}
      onPaste={paste}
    >
      {children}
      {dragging && (
        <div
          className={`${HILOS_UPLOAD_FIELD_OVERLAY_CLASS} hilos-upload-overlay`}
          data-id={`${dataId}-overlay`}
        >
          <span className="text-primary fw-semibold">
            <i className="bi bi-paperclip me-1" aria-hidden="true"></i>
            {COPY.overlay}
          </span>
        </div>
      )}
      <div
        className="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id={`${dataId}-alert`}
      >
        {alert}
      </div>
      <div
        className="visually-hidden"
        role="status"
        aria-live="polite"
        data-id={`${dataId}-status`}
      >
        {status}
      </div>
    </div>
  )
}
