import { SignalDefinition } from '@hilos/sdk/services/signals'
import type {
  AttachmentDraftPayload,
  FileUploadProgressPayload,
  OutboundModerationStatePayload,
  SelfConnectionPayload,
} from '@/stores'

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

const parseSelfConnection = (raw: unknown): SelfConnectionPayload | null => {
  if (!isRecord(raw)) return null
  const {
    userId,
    connectedAt,
    messageRateLimitSecondsRemaining,
    outboundModerationState,
    attachmentDrafts,
    fileUploadProgress,
  } = raw
  if (typeof userId !== 'number' || typeof connectedAt !== 'number') return null
  if (typeof messageRateLimitSecondsRemaining !== 'number') return null
  const parsedDrafts = parseAttachmentDrafts(attachmentDrafts)
  if (parsedDrafts === null) return null
  const parsedModeration =
    outboundModerationState === null || outboundModerationState === undefined
      ? null
      : parseOutboundModerationState(outboundModerationState)
  if (
    outboundModerationState !== null
    && outboundModerationState !== undefined
    && parsedModeration === null
  ) return null
  const parsedProgress =
    fileUploadProgress === null || fileUploadProgress === undefined
      ? null
      : parseFileUploadProgress(fileUploadProgress)
  if (fileUploadProgress !== null && fileUploadProgress !== undefined && parsedProgress === null) return null
  return {
    userId,
    connectedAt,
    messageRateLimitSecondsRemaining,
    outboundModerationState: parsedModeration,
    attachmentDrafts: parsedDrafts,
    fileUploadProgress: parsedProgress,
  }
}

/**
 * Chat session fields merged by ChatEventSignalDTO when
 * the main page includes a connection-local session summary.
 */
export interface ChatSessionFields {
  selfConnection?: SelfConnectionPayload
  /** Retain the raw payload for forwarding to the legacy prefix/table handlers. */
  raw: Record<string, unknown>
}

const parseChatSessionFields = (raw: unknown): ChatSessionFields | null => {
  if (!isRecord(raw)) return null
  const result: ChatSessionFields = { raw }
  if ('selfConnection' in raw) {
    const selfConnection = parseSelfConnection(raw.selfConnection)
    if (selfConnection === null) return null
    result.selfConnection = selfConnection
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
 * `self_connection_update` — current connection-local chat session state.
 */
export interface SelfConnectionUpdatePayload {
  selfConnection: SelfConnectionPayload
}

export const selfConnectionUpdate = new SignalDefinition<
  'self_connection_update',
  SelfConnectionUpdatePayload
>('self_connection_update', (raw: unknown): SelfConnectionUpdatePayload | null => {
  if (!isRecord(raw) || !('selfConnection' in raw)) return null
  const selfConnection = parseSelfConnection(raw.selfConnection)
  return selfConnection === null ? null : { selfConnection }
})

/**
 * `file_upload_progress_update` — periodic connection summary for an in-flight upload.
 */
export const fileUploadProgressUpdate = new SignalDefinition<
  'file_upload_progress_update',
  SelfConnectionUpdatePayload
>('file_upload_progress_update', (raw: unknown): SelfConnectionUpdatePayload | null => {
  if (!isRecord(raw) || !('selfConnection' in raw)) return null
  const selfConnection = parseSelfConnection(raw.selfConnection)
  return selfConnection === null ? null : { selfConnection }
})
