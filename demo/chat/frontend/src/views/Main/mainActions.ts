// The main chat page actions: client-to-server submits the view fires. Selectors
// (mainPage.ts) read the page payload; this module writes back over the same
// connection through the core sendAction primitive. The message action mirrors
// the backend MainPage ACTIONS entry (ChatSignalConstants::MESSAGE → its
// MessageActionDTO {content}); the publish only happens once the backend
// moderates and emits the event, never optimistically here. A rejected submit
// comes back as a framework `action_error`, handled by the core ActionErrorStore
// (bootstrap/connection.ts); this module just exposes the `message` action's slice.
import { type ReadonlySignal } from '@hilos/core'

import { actionErrors, connection } from '../../bootstrap/connection'

/** Backend action name routed by MainPage (PHP `ChatSignalConstants::MESSAGE`). */
const MESSAGE_ACTION = 'message'

/** Backend action: announce a binary file upload (PHP `ChatSignalConstants::FILE_UPLOAD_INIT`). */
const FILE_UPLOAD_INIT_ACTION = 'file_upload_init'

/** Backend action: delete one completed attachment draft (PHP `ChatSignalConstants::ATTACHMENT_DRAFT_DELETE`). */
const ATTACHMENT_DRAFT_DELETE_ACTION = 'attachment_draft_delete'

/**
 * Re-send lockout in seconds shown after a submit, mirroring the backend
 * `ChatUserState::MESSAGE_RATE_LIMIT_SECONDS`. The backend tolerates a re-send
 * one second early, so the client countdown is the safe upper bound; the
 * backend-reported remaining (`messageRateLimitSecondsRemaining`) reconciles it.
 */
export const MESSAGE_RATE_LIMIT_SECONDS = 10

/** The latest message-send error reason, or null when the composer is clear. */
export const messageError: ReadonlySignal<string | null> =
  actionErrors.signal(MESSAGE_ACTION)

/**
 * Submit a chat message: clear any prior error and send the `message` action
 * carrying the text content. Returns false, sending nothing, when the
 * connection is not `connected`.
 *
 * @param content The message text to submit.
 */
export function sendChatMessage(content: string): boolean {
  actionErrors.clear(MESSAGE_ACTION)

  return connection.sendAction(MESSAGE_ACTION, { content })
}

/** Metadata announced ahead of a file upload's binary frames (mirrors PHP `FileUploadInitActionDTO`). */
export interface FileUploadInit {
  filename: string
  mimeType: string
  size: number
  /** Client-minted id correlating the upload to its `selfConnection.fileUpload` state. */
  clientUploadId: string
}

/**
 * Announce a file upload so the backend reserves a slot and replies — over the
 * `selfConnection.fileUpload` state — with `ready` to stream, or `failed`.
 * Returns false, sending nothing, when the connection is not `connected`.
 *
 * @param meta The upload metadata, including the correlating clientUploadId.
 */
export function initFileUpload(meta: FileUploadInit): boolean {
  return connection.sendAction(FILE_UPLOAD_INIT_ACTION, meta)
}

/**
 * The byte size each upload chunk is sliced to before streaming (64 KiB) — a
 * sane WebSocket binary-frame size. The backend reassembles the frames in
 * order, so the value carries no protocol meaning beyond the slice length.
 */
export const UPLOAD_CHUNK_SIZE = 65536

/**
 * Stream one binary chunk of the active upload over the frame_binary channel.
 * Returns false, sending nothing, when the connection is not `connected`.
 *
 * @param chunk The file slice to send.
 */
export function sendUploadChunk(chunk: ArrayBuffer): boolean {
  return connection.sendBinary(chunk)
}

/**
 * Delete one completed attachment draft before the message is sent. Returns
 * false, sending nothing, when the connection is not `connected`.
 *
 * @param draftId The draft to remove.
 */
export function deleteAttachmentDraft(draftId: string): boolean {
  return connection.sendAction(ATTACHMENT_DRAFT_DELETE_ACTION, { draftId })
}
