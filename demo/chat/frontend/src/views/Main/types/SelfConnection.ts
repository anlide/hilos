// The current connection's own composer state — a value type, not an entity. It
// rides the main page's `selfConnection` data slot (a single-row backend DATA
// source keyed by this socket's acceptKey), carrying the re-send rate-limit
// countdown, the outbound moderation state, and the active file upload's status
// and byte progress the composer reflects. Wire keys mirror the backend
// SelfConnectionSignalData / OutboundModerationBrowserPayload.
import { readNumber, readString, readStringOrNull } from '@hilos/core'

/** Outbound moderation lifecycle phase (PHP `ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_*`). */
export type ModerationPhase = 'checking' | 'rejected' | 'unavailable'

const MODERATION_PHASES: readonly ModerationPhase[] = [
  'checking',
  'rejected',
  'unavailable',
]

/** File-upload lifecycle phase (PHP `ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_*`); the idle phase projects to null. */
export type FileUploadPhase = 'ready' | 'uploading' | 'failed'

const FILE_UPLOAD_PHASES: readonly FileUploadPhase[] = [
  'ready',
  'uploading',
  'failed',
]

/** The connection-local outbound moderation state, present only while a submit is in flight or unresolved. */
export interface OutboundModeration {
  phase: ModerationPhase
  /** The submitted text held by the backend — restored into the input on a non-checking phase. */
  text: string
  /** A rejection or unavailable reason, or null. */
  reason: string | null
}

/** The active file upload's status, present only while an upload is in flight or unresolved. */
export interface FileUpload {
  phase: FileUploadPhase
  /** Echo of the client's upload id — correlates this status to the upload that started it. */
  clientUploadId: string | null
  /** A failure code (PHP `ChatFileUploadConstants`) when the phase is `failed`, otherwise null. */
  errorCode: string | null
  /** A human-readable failure reason when the phase is `failed`, otherwise null. */
  errorMessage: string | null
}

/** The active file upload's byte progress, present only while bytes stream. */
export interface FileUploadProgress {
  filename: string
  uploadedBytes: number
  totalBytes: number
}

/** The composer-relevant projection of the `selfConnection` data slot. */
export interface SelfConnection {
  /** Seconds the backend still rate-limits the next submit; 0 when free to send. */
  messageRateLimitSecondsRemaining: number
  /** The outbound moderation state, or null when none is in flight. */
  moderation: OutboundModeration | null
  /** The active upload's status, or null when no upload is in flight or unresolved. */
  fileUpload: FileUpload | null
  /** The active upload's byte progress, or null when no bytes are streaming. */
  fileProgress: FileUploadProgress | null
}

/**
 * Narrow the `outboundModerationState` nested slot to a typed moderation state,
 * or null when absent or carrying an unknown phase.
 *
 * @param raw The raw `outboundModerationState` value from the payload.
 */
function toModeration(raw: unknown): OutboundModeration | null {
  if (typeof raw !== 'object' || raw === null) {
    return null
  }
  const fields = raw as Record<string, unknown>
  const phase = fields.phase
  if (!MODERATION_PHASES.includes(phase as ModerationPhase)) {
    return null
  }

  return {
    phase: phase as ModerationPhase,
    text: readString(fields, 'text'),
    reason: readStringOrNull(fields, 'reason'),
  }
}

/**
 * Narrow the `fileUploadState` nested slot to a typed upload status, or null
 * when absent or carrying an unknown phase (the idle phase is sent as null).
 *
 * @param raw The raw `fileUploadState` value from the payload.
 */
function toFileUpload(raw: unknown): FileUpload | null {
  if (typeof raw !== 'object' || raw === null) {
    return null
  }
  const fields = raw as Record<string, unknown>
  const phase = fields.phase
  if (!FILE_UPLOAD_PHASES.includes(phase as FileUploadPhase)) {
    return null
  }

  return {
    phase: phase as FileUploadPhase,
    clientUploadId: readStringOrNull(fields, 'clientUploadId'),
    errorCode: readStringOrNull(fields, 'errorCode'),
    errorMessage: readStringOrNull(fields, 'errorMessage'),
  }
}

/**
 * Narrow the `fileUploadProgress` nested slot to a typed byte progress, or null
 * when absent (no upload is streaming).
 *
 * @param raw The raw `fileUploadProgress` value from the payload.
 */
function toFileUploadProgress(raw: unknown): FileUploadProgress | null {
  if (typeof raw !== 'object' || raw === null) {
    return null
  }
  const fields = raw as Record<string, unknown>

  return {
    filename: readString(fields, 'filename'),
    uploadedBytes: readNumber(fields, 'uploadedBytes'),
    totalBytes: readNumber(fields, 'totalBytes'),
  }
}

/**
 * Project the raw `selfConnection` data slot into the typed composer state, or
 * undefined before the first selfConnection payload lands.
 *
 * @param raw The raw `selfConnection` data-slot value from the page scope.
 */
export function toSelfConnection(raw: unknown): SelfConnection | undefined {
  if (typeof raw !== 'object' || raw === null) {
    return undefined
  }
  const fields = raw as Record<string, unknown>

  return {
    messageRateLimitSecondsRemaining: readNumber(
      fields,
      'messageRateLimitSecondsRemaining',
    ),
    moderation: toModeration(fields.outboundModerationState),
    fileUpload: toFileUpload(fields.fileUploadState),
    fileProgress: toFileUploadProgress(fields.fileUploadProgress),
  }
}
