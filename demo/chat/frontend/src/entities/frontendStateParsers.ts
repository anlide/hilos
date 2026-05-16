import { type Presence, isPresence } from '@/types/domain/Presence'

type JsonRecord = Record<string, unknown>

export type FileUploadProgressPayload = {
  filename: string
  uploadedBytes: number
  totalBytes: number
}

export type FileUploadStatePayload = {
  phase: 'ready' | 'uploading' | 'failed'
  clientUploadId: string | null
  errorCode: string | null
  errorMessage: string | null
}

export type OutboundModerationStatePayload = {
  phase: 'checking' | 'rejected' | 'unavailable'
  text: string
  reason: string | null
  updatedAt: number
}

export type SelfConnectionPayload = {
  userId: number
  connectedAt: number
  messageRateLimitSecondsRemaining: number
  outboundModerationState: OutboundModerationStatePayload | null
  fileUploadState: FileUploadStatePayload | null
  fileUploadProgress: FileUploadProgressPayload | null
}

export type UserPresencePayload = {
  userId: number
  presence: Presence
}

export type UserConnectionStatsPayload = {
  userId: number
  onlineSessionCount: number
}

export type AttachmentDraftPayload = {
  draftId: string
  filename: string
  mimeType: string
  size: number
  uploadedAt: number
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const parseOutboundModerationState = (raw: unknown): OutboundModerationStatePayload | null => {
  if (raw === null || raw === undefined) return null
  if (!isRecord(raw)) return null
  const { phase, text, reason, updatedAt } = raw
  if (phase !== 'checking' && phase !== 'rejected' && phase !== 'unavailable') return null
  if (typeof text !== 'string') return null
  if (reason !== null && reason !== undefined && typeof reason !== 'string') return null
  if (typeof updatedAt !== 'number') return null
  return {
    phase,
    text,
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

const parseFileUploadState = (raw: unknown): FileUploadStatePayload | null => {
  if (!isRecord(raw)) return null
  const { phase, clientUploadId, errorCode, errorMessage } = raw
  if (phase !== 'ready' && phase !== 'uploading' && phase !== 'failed') return null
  if (clientUploadId !== null && clientUploadId !== undefined && typeof clientUploadId !== 'string') return null
  if (errorCode !== null && errorCode !== undefined && typeof errorCode !== 'string') return null
  if (errorMessage !== null && errorMessage !== undefined && typeof errorMessage !== 'string') return null
  return {
    phase,
    clientUploadId: typeof clientUploadId === 'string' ? clientUploadId : null,
    errorCode: typeof errorCode === 'string' ? errorCode : null,
    errorMessage: typeof errorMessage === 'string' ? errorMessage : null,
  }
}

export const parseSelfConnection = (raw: unknown): SelfConnectionPayload | null => {
  if (!isRecord(raw)) return null
  const {
    userId,
    connectedAt,
    messageRateLimitSecondsRemaining,
    outboundModerationState,
    fileUploadState,
    fileUploadProgress,
  } = raw
  if (typeof userId !== 'number' || typeof connectedAt !== 'number') return null
  if (typeof messageRateLimitSecondsRemaining !== 'number') return null
  const parsedModeration =
    outboundModerationState === null || outboundModerationState === undefined
      ? null
      : parseOutboundModerationState(outboundModerationState)
  if (
    outboundModerationState !== null
    && outboundModerationState !== undefined
    && parsedModeration === null
  ) return null
  const parsedUploadState =
    fileUploadState === null || fileUploadState === undefined
      ? null
      : parseFileUploadState(fileUploadState)
  if (fileUploadState !== null && fileUploadState !== undefined && parsedUploadState === null) return null
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
    fileUploadState: parsedUploadState,
    fileUploadProgress: parsedProgress,
  }
}

export function isUserPresencePayload(value: unknown): value is UserPresencePayload {
  return isRecord(value) && typeof value.userId === 'number' && isPresence(value.presence)
}

export function parseUserPresencePayloads(value: unknown): UserPresencePayload[] | null {
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isUserPresencePayload) ? value : null
}

export function isUserConnectionStatsPayload(value: unknown): value is UserConnectionStatsPayload {
  return isRecord(value) && typeof value.userId === 'number' && typeof value.onlineSessionCount === 'number'
}

export function parseUserConnectionStatsPayloads(value: unknown): UserConnectionStatsPayload[] | null {
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isUserConnectionStatsPayload) ? value : null
}

export function isAttachmentDraftPayload(value: unknown): value is AttachmentDraftPayload {
  return isRecord(value)
    && typeof value.draftId === 'string'
    && typeof value.filename === 'string'
    && typeof value.mimeType === 'string'
    && typeof value.size === 'number'
    && typeof value.uploadedAt === 'number'
}
