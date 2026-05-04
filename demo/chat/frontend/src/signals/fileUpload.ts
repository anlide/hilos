import { SignalDefinition, parseEmptyPayload } from '@hilos/sdk/services/signals'
import type { AttachmentDraftPayload } from '@/entities/frontendStateParsers'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * `file_upload_ready` — server accepted file_upload_init; client may start
 * streaming binary chunks. Carries the initial 0/size baseline for UI.
 */
export interface FileUploadReadyPayload {
  filename: string
  size: number
}

export const fileUploadReady = new SignalDefinition<'file_upload_ready', FileUploadReadyPayload>(
  'file_upload_ready',
  (raw: unknown): FileUploadReadyPayload | null => {
    if (!isRecord(raw)) return null
    const { filename, size } = raw
    if (typeof filename !== 'string' || typeof size !== 'number') return null
    return { filename, size }
  },
)

/**
 * `file_upload_rejected` — server refused file_upload_init. The pending
 * upload promise is rejected/resolved with this payload.
 */
export interface FileUploadRejectedPayload {
  code: string
  message?: string
}

export const fileUploadRejected = new SignalDefinition<
  'file_upload_rejected',
  FileUploadRejectedPayload
>('file_upload_rejected', (raw: unknown): FileUploadRejectedPayload | null => {
  if (!isRecord(raw)) return null
  const code = typeof raw.code === 'string' ? raw.code : String(raw.code ?? '')
  const message = typeof raw.message === 'string' ? raw.message : undefined
  return message !== undefined ? { code, message } : { code }
})

/**
 * `file_upload_aborted` — upload terminated server-side (e.g. after timeout).
 * No payload body; dedicated signal purely for type-level clarity.
 */
export const fileUploadAborted = new SignalDefinition<'file_upload_aborted', undefined>(
  'file_upload_aborted',
  parseEmptyPayload,
)

/**
 * `file_upload_invalid` — upload was structurally invalid (e.g. size mismatch).
 */
export const fileUploadInvalid = new SignalDefinition<'file_upload_invalid', undefined>(
  'file_upload_invalid',
  parseEmptyPayload,
)

/**
 * `file_upload_complete` — full file received and converted to an attachment draft.
 */
export interface FileUploadCompletePayload {
  attachmentDraft: AttachmentDraftPayload
}

const parseAttachmentDraft = (raw: unknown): AttachmentDraftPayload | null => {
  if (!isRecord(raw)) return null
  const { draftId, filename, mimeType, size, uploadedAt } = raw
  if (typeof draftId !== 'string') return null
  if (typeof filename !== 'string' || typeof mimeType !== 'string') return null
  if (typeof size !== 'number' || typeof uploadedAt !== 'number') return null
  return { draftId, filename, mimeType, size, uploadedAt }
}

export const fileUploadComplete = new SignalDefinition<'file_upload_complete', FileUploadCompletePayload>(
  'file_upload_complete',
  (raw: unknown): FileUploadCompletePayload | null => {
    if (!isRecord(raw)) return null
    const attachmentDraft = parseAttachmentDraft(raw.attachmentDraft)
    return attachmentDraft === null ? null : { attachmentDraft }
  },
)
