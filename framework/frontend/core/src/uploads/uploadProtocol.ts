import { z } from 'zod'

/** Action `type` for declaring an upload (PHP `HilosSignalConstants::HILOS_UPLOAD_INIT`). */
export const UPLOAD_ACTION_INIT = 'hilos_upload_init'

/** Action `type` for canceling an upload (PHP `HilosSignalConstants::HILOS_UPLOAD_CANCEL`). */
export const UPLOAD_ACTION_CANCEL = 'hilos_upload_cancel'

/** Signal `type` for upload state (PHP `HilosSignalConstants::HILOS_UPLOAD_STATE`). */
export const SIGNAL_UPLOAD_STATE = 'hilos_upload_state'

/** Client-only phase before the server knows about an upload. */
export const UPLOAD_PHASE_QUEUED = 'queued'

/** Accepted phase (PHP `UploadPhase::READY`). */
export const UPLOAD_PHASE_READY = 'ready'

/** Receiving phase (PHP `UploadPhase::UPLOADING`). */
export const UPLOAD_PHASE_UPLOADING = 'uploading'

/** Complete phase (PHP `UploadPhase::COMPLETE`). */
export const UPLOAD_PHASE_COMPLETE = 'complete'

/** Failed phase (PHP `UploadPhase::FAILED`). */
export const UPLOAD_PHASE_FAILED = 'failed'

/** Every phase the browser upload client can expose. */
export type HilosUploadPhase =
  | typeof UPLOAD_PHASE_QUEUED
  | typeof UPLOAD_PHASE_READY
  | typeof UPLOAD_PHASE_UPLOADING
  | typeof UPLOAD_PHASE_COMPLETE
  | typeof UPLOAD_PHASE_FAILED

/** Client failure code for a refused upload declaration. */
export const UPLOAD_ERROR_REFUSED = 'refused'

/** Client failure code for an upload declaration that received no reply. */
export const UPLOAD_ERROR_TIMEOUT = 'timeout'

/** Client failure code for an unfinished upload the server removed. */
export const UPLOAD_ERROR_INTERRUPTED = 'interrupted'

/** Client failure code for file bytes the browser could not read. */
export const UPLOAD_ERROR_UNREADABLE = 'unreadable'

/** Copy owned by failures that originate in the browser upload client. */
export const UPLOAD_COPY = {
  interrupted: 'Upload interrupted',
  unreadable: 'The file could not be read',
} as const

/** Upload state payload (PHP `UploadStateSignalData`). */
export const uploadStateSchema = z.looseObject({
  clientUploadId: z.string(),
  phase: z.string().nullable().default(null),
  receivedBytes: z.number().nullable().default(null),
  declaredSize: z.number().nullable().default(null),
  errorCode: z.string().nullable().default(null),
  errorMessage: z.string().nullable().default(null),
})

/** Project signal schemas understood by the browser upload client. */
export const UPLOAD_SIGNAL_SCHEMAS = {
  [SIGNAL_UPLOAD_STATE]: uploadStateSchema,
}

/** File bytes carried by one binary upload frame. */
export const UPLOAD_CHUNK_BYTES = 65_536

/** Socket backlog above which the stream waits before reading another chunk. */
export const UPLOAD_BUFFER_HIGH_WATER_BYTES = 1_048_576

/** Delay between checks of a socket whose send buffer is above the high-water mark. */
export const UPLOAD_BUFFER_POLL_MS = 20

/** Random bytes in a client-minted upload id. */
export const UPLOAD_ID_BYTES = 16

const MAX_UPLOAD_ID_BYTES = 64
const UPLOAD_ID_PATTERN = /^[A-Za-z0-9_-]+$/

/**
 * Mint an upload id from the platform's secure random source.
 *
 * @returns {@link UPLOAD_ID_BYTES} random bytes encoded as lowercase hex.
 */
export function mintUploadId(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(UPLOAD_ID_BYTES))

  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join(
    '',
  )
}

/**
 * Prefix one file chunk with the upload id understood by PHP `UploadFrame`.
 *
 * @param clientUploadId Upload the chunk belongs to.
 * @param bytes File bytes carried by this chunk.
 * @returns Binary frame `[1 byte id length][ASCII id][file bytes]`.
 */
export function signUploadChunk(
  clientUploadId: string,
  bytes: ArrayBuffer,
): ArrayBuffer {
  if (
    clientUploadId.length < 1 ||
    clientUploadId.length > MAX_UPLOAD_ID_BYTES ||
    !UPLOAD_ID_PATTERN.test(clientUploadId)
  ) {
    throw new Error('Invalid upload id')
  }

  const id = new TextEncoder().encode(clientUploadId)
  const frame = new Uint8Array(1 + id.byteLength + bytes.byteLength)
  frame[0] = id.byteLength
  frame.set(id, 1)
  frame.set(new Uint8Array(bytes), 1 + id.byteLength)

  return frame.buffer
}
