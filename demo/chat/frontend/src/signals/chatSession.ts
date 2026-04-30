import { SignalDefinition } from '@hilos/sdk/services/signals'
import type { AttachmentDraftPayload, FileUploadProgressPayload, OutboundModerationStatePayload } from '@/stores'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const parseAttachmentDraft = (raw: unknown): AttachmentDraftPayload | null => {
  if (!isRecord(raw)) return null
  const { draftId, filename, mimeType, size, uploadedAt } = raw
  if (typeof draftId !== 'string') return null
  if (typeof filename !== 'string' || typeof mimeType !== 'string') return null
  if (typeof size !== 'number' || typeof uploadedAt !== 'number') return null
  return { draftId, filename, mimeType, size, uploadedAt }
}

const parseAttachmentDrafts = (raw: unknown): AttachmentDraftPayload[] | null => {
  if (!Array.isArray(raw)) return null
  const drafts = raw.map(parseAttachmentDraft)
  if (drafts.some((draft) => draft === null)) return null
  return drafts as AttachmentDraftPayload[]
}

const parseOutboundModerationState = (raw: unknown): OutboundModerationStatePayload | null => {
  if (raw === null || raw === undefined) return null
  if (!isRecord(raw)) return null
  const { requestId, phase, text, attachments, reason, updatedAt } = raw
  if (typeof requestId !== 'string') return null
  if (phase !== 'checking' && phase !== 'rejected' && phase !== 'unavailable') return null
  if (typeof text !== 'string') return null
  const parsedAttachments = parseAttachmentDrafts(attachments)
  if (parsedAttachments === null) return null
  if (reason !== null && reason !== undefined && typeof reason !== 'string') return null
  if (typeof updatedAt !== 'number') return null
  return {
    requestId,
    phase,
    text,
    attachments: parsedAttachments,
    reason: typeof reason === 'string' ? reason : null,
    updatedAt,
  }
}

const parseFileUploadProgress = (raw: unknown): FileUploadProgressPayload | null => {
  if (!isRecord(raw)) return null
  const { filename, uploadedBytes, totalBytes } = raw
  if (typeof filename !== 'string') return null
  if (typeof uploadedBytes !== 'number' || typeof totalBytes !== 'number') return null
  return { filename, uploadedBytes, totalBytes }
}

/**
 * Chat session fields merged by ChatEventSignalDTO when
 * `includeUserSessionFields` is true. Keys may be absent (field not present)
 * or explicitly `null` (present but empty).
 */
export interface ChatSessionFields {
  outboundModerationState?: OutboundModerationStatePayload | null
  attachmentDrafts?: AttachmentDraftPayload[]
  fileUploadProgress?: FileUploadProgressPayload | null
  /** Retain the raw payload for forwarding to the legacy prefix/table handlers. */
  raw: Record<string, unknown>
}

const parseChatSessionFields = (raw: unknown): ChatSessionFields | null => {
  if (!isRecord(raw)) return null
  const result: ChatSessionFields = { raw }
  if ('outboundModerationState' in raw) {
    result.outboundModerationState = parseOutboundModerationState(raw.outboundModerationState)
  }
  if ('attachmentDrafts' in raw) {
    result.attachmentDrafts = parseAttachmentDrafts(raw.attachmentDrafts) ?? []
  }
  if ('fileUploadProgress' in raw) {
    const v = raw.fileUploadProgress
    result.fileUploadProgress = v === null || v === undefined ? null : parseFileUploadProgress(v)
  }
  return result
}

/**
 * `subscription_page_main` delivers the user's own chat page state,
 * including outbound moderation, draft attachments, and ongoing binary upload progress.
 */
export const subscriptionPageMain = new SignalDefinition<
  'subscription_page_main',
  ChatSessionFields
>('subscription_page_main', parseChatSessionFields)

/**
 * `outbound_moderation_state_update` — current outbound moderation state.
 */
export interface OutboundModerationStateUpdatePayload {
  outboundModerationState: OutboundModerationStatePayload | null
}

export const outboundModerationStateUpdate = new SignalDefinition<
  'outbound_moderation_state_update',
  OutboundModerationStateUpdatePayload
>('outbound_moderation_state_update', (raw: unknown): OutboundModerationStateUpdatePayload | null => {
  if (!isRecord(raw) || !('outboundModerationState' in raw)) return null
  return { outboundModerationState: parseOutboundModerationState(raw.outboundModerationState) }
})

/**
 * `attachment_drafts_update` — full uploaded attachment draft list for this connection.
 */
export interface AttachmentDraftsUpdatePayload {
  attachmentDrafts: AttachmentDraftPayload[]
}

export const attachmentDraftsUpdate = new SignalDefinition<
  'attachment_drafts_update',
  AttachmentDraftsUpdatePayload
>('attachment_drafts_update', (raw: unknown): AttachmentDraftsUpdatePayload | null => {
  if (!isRecord(raw) || !('attachmentDrafts' in raw)) return null
  const attachmentDrafts = parseAttachmentDrafts(raw.attachmentDrafts)
  return attachmentDrafts === null ? null : { attachmentDrafts }
})

/**
 * `file_upload_progress_update` — periodic progress tick for an in-flight upload.
 */
export interface FileUploadProgressUpdatePayload {
  fileUploadProgress: FileUploadProgressPayload | null
}

export const fileUploadProgressUpdate = new SignalDefinition<
  'file_upload_progress_update',
  FileUploadProgressUpdatePayload
>('file_upload_progress_update', (raw: unknown): FileUploadProgressUpdatePayload | null => {
  if (!isRecord(raw) || !('fileUploadProgress' in raw)) return null
  const v = raw.fileUploadProgress
  if (v === null || v === undefined) {
    return { fileUploadProgress: null }
  }
  return { fileUploadProgress: parseFileUploadProgress(v) }
})
