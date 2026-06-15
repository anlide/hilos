// The moderation admin page selectors: the page-scoped prompt-pieces table rows
// fed into a headless TableController, each raw row resolved into its
// ModeratorPieceRow view-model. A piece carries an id, so the wire delivers it as
// an entity reference in the `moderatorPromptPieces` slot (an id-bearing slot is
// an entity by convention), resolved through the ModeratorPieces collection. The
// view reads the controller, never a raw store.
import { TableController, type EntityRef, type TableRow } from '@hilos/core'

import { scopes } from '../../bootstrap/session'
import { ModeratorPieces } from '../../types'
import {
  type ModeratorPieceRow,
  type ModeratorSection,
} from './types/tables/ModeratorPieceRow'

// Wire keys: the moderator prompt-pieces table (ChatTableContext::moderatorPromptPieces)
// and its row's entity-ref slot (ChatDbContext::moderatorPromptPieces).
const PIECES_TABLE = 'moderatorPromptPieces'
const PIECES_SLOT = 'moderatorPromptPieces'

/** The moderation rule sections, mirroring the backend ObjectModeratorPromptPiece. */
export const MODERATOR_SECTIONS: readonly ModeratorSection[] = [
  'name_rule',
  'message_rule',
]

/** Narrow an unknown section to the typed union, defaulting to message_rule. */
function toSection(value: unknown): ModeratorSection {
  return MODERATOR_SECTIONS.includes(value as ModeratorSection)
    ? (value as ModeratorSection)
    : 'message_rule'
}

/**
 * Resolve one raw prompt-pieces table row into its view-model. The piece is
 * delivered as an entity reference in the `moderatorPromptPieces` slot, so this
 * resolves it through the ModeratorPieces collection — reading through the
 * collection keeps the row reactive to an edit.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveModeratorPieceRow(row: TableRow): ModeratorPieceRow {
  const ref = row.slots[PIECES_SLOT] as EntityRef | undefined
  const piece = ref ? ModeratorPieces.signal(ref).get() : undefined

  return {
    id: Number(piece?.id ?? row.rowKey),
    section: toSection(piece?.section),
    promptPiece: piece?.promptPiece ?? '',
  }
}

/**
 * The headless controller for the moderation prompt-pieces table (client
 * viewport): search by section or prompt text, sort by section. Rows resolve
 * through {@link resolveModeratorPieceRow}.
 */
export const moderatorPiecesTable = new TableController<ModeratorPieceRow>({
  source: scopes.pageTableSignal(PIECES_TABLE),
  resolve: resolveModeratorPieceRow,
  searchText: (row) => `${row.section} ${row.promptPiece}`,
  sortValue: (row, field) => (field === 'section' ? row.section : row.id),
  initialSort: { field: 'id', direction: 'asc' },
})
