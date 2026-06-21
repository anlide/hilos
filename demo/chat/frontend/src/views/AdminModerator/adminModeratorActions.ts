// The moderation admin actions: the create / update / delete submits as tracked
// actions over the connection's reply lifecycle (action-lifecycle, step 7.4).
// Each returns an ActionHandle whose `done` resolves on the backend's
// `::success` ack and rejects (with the reason) on `::fail` — the view closes
// the modal on success and surfaces the failure (authoritative-backend, no
// optimism). The committed row returns separately over the live table, so the
// success ack arrives even when the edit changed nothing (no more no-op hang).
import { type ActionHandle } from '@hilos/core'

import { actions } from '../../bootstrap/connection'
import { moderatorPiecesTable } from './adminModeratorPage'
import { type ModeratorSection } from './types/tables/ModeratorPieceRow'

// Backend action names (PHP ChatSignalConstants).
const MODERATOR_PIECE_CREATE = 'moderator_piece_create'
const MODERATOR_PIECE_UPDATE = 'moderator_piece_update'
const MODERATOR_PIECE_DELETE = 'moderator_piece_delete'

/** The editable fields a prompt piece create or update carries. */
export interface ModeratorPieceInput {
  /** The moderation rule section the piece belongs to. */
  section: ModeratorSection
  /** The prompt text (required). */
  promptPiece: string
}

/**
 * Create a prompt piece as a tracked action.
 *
 * @param input The new piece's fields.
 */
export function sendModeratorPieceCreate(
  input: ModeratorPieceInput,
): ActionHandle {
  return actions.dispatch(MODERATOR_PIECE_CREATE, { ...input })
}

/**
 * Update a prompt piece's fields as a tracked action.
 *
 * @param id The piece id to update.
 * @param input The piece's new fields.
 */
export function sendModeratorPieceUpdate(
  id: number,
  input: ModeratorPieceInput,
): ActionHandle {
  const handle = actions.dispatch(MODERATOR_PIECE_UPDATE, { id, ...input })
  // Auto-apply this tab's own echo for the edited row; other tabs keep the pending gate.
  moderatorPiecesTable.expectOwnChange(String(id), handle.done)

  return handle
}

/**
 * Delete a prompt piece as a tracked action.
 *
 * @param id The piece id to delete.
 */
export function sendModeratorPieceDelete(id: number): ActionHandle {
  const handle = actions.dispatch(MODERATOR_PIECE_DELETE, { id })
  moderatorPiecesTable.expectOwnChange(String(id), handle.done)

  return handle
}
