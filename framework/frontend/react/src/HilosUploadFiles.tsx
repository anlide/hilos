// HilosUploadFiles — the chips of the upload field's files and the line of its
// last refusal above them. With no files it draws nothing and takes no room;
// from the first file to the last the refusal's room is held by
// HilosFormError's invisible twin, so a refusal moves nothing (styling-rules.md,
// "The room a live message takes"). The line carries no voice: HilosUploadDrop
// says it aloud, and an announcement here would be heard twice.
// A chip's reading lives on data-state, its icon and tone show it, and a hidden
// word says it — an icon and a color reach no ear.
import {
  HILOS_UPLOAD_FIELD_CHIP_CALM_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_FAILED_CLASS,
  HILOS_UPLOAD_FIELD_COPY as COPY,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core/base'

import { HilosFormError } from './HilosFormError.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosUploadFiles}. */
export interface HilosUploadFilesProps {
  /** The upload field the page created; the page disposes it, not this tag. */
  readonly field: HilosUploadField
  /** The data-id of the block; the refusal line and the chips derive theirs from it. */
  readonly dataId: string
}

function chipClass(file: HilosUploadFieldFile): string {
  const tone =
    file.reading === 'failed'
      ? HILOS_UPLOAD_FIELD_CHIP_FAILED_CLASS
      : HILOS_UPLOAD_FIELD_CHIP_CALM_CLASS

  return file.canceling
    ? `${HILOS_UPLOAD_FIELD_CHIP_CLASS} ${tone} ${HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS}`
    : `${HILOS_UPLOAD_FIELD_CHIP_CLASS} ${tone}`
}

function ChipLead({ file }: { file: HilosUploadFieldFile }) {
  switch (file.reading) {
    case 'waiting':
      return (
        <>
          <i className="bi bi-clock" aria-hidden="true"></i>
          <span className="visually-hidden">{COPY.waiting}</span>
        </>
      )
    case 'uploading':
      return (
        <>
          <span className="visually-hidden">{COPY.uploading}</span>
          <span className="hilos-upload-percent d-inline-block text-end">
            {file.percent}%
          </span>
        </>
      )
    case 'received':
      return <i className="bi bi-paperclip" aria-hidden="true"></i>
    default:
      return (
        <>
          <i className="bi bi-exclamation-circle" aria-hidden="true"></i>
          <span className="visually-hidden">{COPY.failed}</span>
        </>
      )
  }
}

/**
 * Draw the chips of an upload field and the room of its refusal line.
 *
 * @param props The field and the data-id of the block.
 */
export function HilosUploadFiles({ field, dataId }: HilosUploadFilesProps) {
  const files = useSignal(field.files)
  const refusal = useSignal(field.refusal)
  const disabled = useSignal(field.disabled)

  if (files.length === 0) {
    return null
  }

  return (
    <div className="mb-2" data-id={dataId}>
      <HilosFormError message={refusal} dataId={`${dataId}-error`} />
      <div className="d-flex flex-wrap gap-2">
        {files.map((file) => (
          <span
            key={file.clientUploadId}
            className={chipClass(file)}
            data-id={`${dataId}-file`}
            data-state={file.reading}
          >
            <ChipLead file={file} />
            <span
              className="text-truncate hilos-upload-name"
              title={file.filename}
              data-id={`${dataId}-file-name`}
            >
              {file.filename}
            </span>
            <button
              type="button"
              className="btn-close hilos-upload-remove"
              aria-label={`${COPY.remove} ${file.filename}`}
              disabled={disabled || file.canceling}
              data-id={`${dataId}-file-remove`}
              onClick={() => field.remove(file.clientUploadId)}
            ></button>
          </span>
        ))}
      </div>
    </div>
  )
}
