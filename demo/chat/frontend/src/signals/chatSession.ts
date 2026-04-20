import { SignalDefinition } from '@hilos/sdk/services/signals'
import type { FileUploadProgressPayload } from '@/stores'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const parseNullableString = (value: unknown): string | null => {
  if (value === null || value === undefined) return null
  return typeof value === 'string' ? value : null
}

const parseNullableRecord = (value: unknown): Record<string, unknown> | null => {
  if (value === null || value === undefined) return null
  return isRecord(value) ? value : null
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
  moderationState?: string | null
  fileModerationState?: Record<string, unknown> | null
  fileUploadProgress?: FileUploadProgressPayload | null
  /** Retain the raw payload for forwarding to the legacy prefix/table handlers. */
  raw: Record<string, unknown>
}

const parseChatSessionFields = (raw: unknown): ChatSessionFields | null => {
  if (!isRecord(raw)) return null
  const result: ChatSessionFields = { raw }
  if ('moderationState' in raw) {
    result.moderationState = parseNullableString(raw.moderationState)
  }
  if ('fileModerationState' in raw) {
    result.fileModerationState = parseNullableRecord(raw.fileModerationState)
  }
  if ('fileUploadProgress' in raw) {
    const v = raw.fileUploadProgress
    result.fileUploadProgress = v === null || v === undefined ? null : parseFileUploadProgress(v)
  }
  return result
}

/**
 * `subscription_page_main` delivers the user's own chat page state,
 * including moderation flags and ongoing binary upload progress.
 */
export const subscriptionPageMain = new SignalDefinition<
  'subscription_page_main',
  ChatSessionFields
>('subscription_page_main', parseChatSessionFields)

/**
 * `moderation_state_update` — broadcast change of pending-moderation text.
 */
export interface ModerationStateUpdatePayload {
  moderationState: string | null
}

export const moderationStateUpdate = new SignalDefinition<
  'moderation_state_update',
  ModerationStateUpdatePayload
>('moderation_state_update', (raw: unknown): ModerationStateUpdatePayload | null => {
  if (!isRecord(raw) || !('moderationState' in raw)) return null
  return { moderationState: parseNullableString(raw.moderationState) }
})

/**
 * `file_moderation_state_update` — change of in-chat file moderation UI state.
 */
export interface FileModerationStateUpdatePayload {
  fileModerationState: Record<string, unknown> | null
}

export const fileModerationStateUpdate = new SignalDefinition<
  'file_moderation_state_update',
  FileModerationStateUpdatePayload
>('file_moderation_state_update', (raw: unknown): FileModerationStateUpdatePayload | null => {
  if (!isRecord(raw) || !('fileModerationState' in raw)) return null
  return { fileModerationState: parseNullableRecord(raw.fileModerationState) }
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
