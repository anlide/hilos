// The moderation admin actions: the create / update / delete submits and their
// shared failure feedback. The backend AdminModeratorPage reports a rejected
// action through the table_action_error signal; it has no registered schema, so it
// arrives as an `unknownSignal` and we react to its type only, never its raw
// payload (the parse boundary stays the one place that interprets a frame —
// wire-protocol.md). Success is observed state-driven in the view (the changed row
// returns over the live table).
import { createSignal, type ReadonlySignal } from '@hilos/core'

import { connection } from '../../bootstrap/connection'
import { type ModeratorSection } from './types/tables/ModeratorPieceRow'

// Backend action and failure signal names (PHP ChatSignalConstants).
const MODERATOR_PIECE_CREATE = 'moderator_piece_create'
const MODERATOR_PIECE_UPDATE = 'moderator_piece_update'
const MODERATOR_PIECE_DELETE = 'moderator_piece_delete'
const TABLE_ACTION_ERROR = 'table_action_error'

/** The editable fields a prompt piece create or update carries. */
export interface ModeratorPieceInput {
  /** The moderation rule section the piece belongs to. */
  section: ModeratorSection
  /** The prompt text (required). */
  promptPiece: string
}

const error = createSignal<string | null>(null)

/** The latest moderation action failure reason, or null when clear. */
export const moderatorError: ReadonlySignal<string | null> = error

// A rejected moderation action arrives as the backend's table_action_error ack;
// surface a generic message (the reason text stays behind the unregistered schema).
connection.on('unknownSignal', (signal) => {
  if (signal.type === TABLE_ACTION_ERROR) {
    error.set('The moderation action could not be completed. Please try again.')
  }
})

/** Clear the moderation error (on opening or cancelling a form). */
export function clearModeratorError(): void {
  error.set(null)
}

/**
 * Create a prompt piece. Returns false, sending nothing, when the connection is
 * not connected.
 *
 * @param input The new piece's fields.
 */
export function sendModeratorPieceCreate(input: ModeratorPieceInput): boolean {
  error.set(null)

  return connection.sendAction(MODERATOR_PIECE_CREATE, { ...input })
}

/**
 * Update a prompt piece's fields. Returns false, sending nothing, when the
 * connection is not connected.
 *
 * @param id The piece id to update.
 * @param input The piece's new fields.
 */
export function sendModeratorPieceUpdate(
  id: number,
  input: ModeratorPieceInput,
): boolean {
  error.set(null)

  return connection.sendAction(MODERATOR_PIECE_UPDATE, { id, ...input })
}

/**
 * Delete a prompt piece. Returns false, sending nothing, when the connection is
 * not connected.
 *
 * @param id The piece id to delete.
 */
export function sendModeratorPieceDelete(id: number): boolean {
  error.set(null)

  return connection.sendAction(MODERATOR_PIECE_DELETE, { id })
}
