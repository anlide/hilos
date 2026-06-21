// The moderation admin page selectors: the page-scoped prompt-pieces table rows
// fed into a server-windowed TableViewportController, each raw row resolved into
// its ModeratorPieceRow view-model. A piece carries an id, so the wire delivers it as
// an entity reference in the `moderatorPromptPieces` slot (an id-bearing slot is
// an entity by convention), resolved through the ModeratorPieces collection. The
// view reads the controller, never a raw store.
import {
  TableViewportController,
  bindTableViewport,
  type EntityRef,
  type TableRow,
} from '@hilos/core'

import { connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'
import { PAGE_ADMIN_MODERATOR } from '../../pages/keys'
import { ModeratorPieces } from '../../types'
import {
  type ModeratorPieceRow,
  type ModeratorSection,
} from './types/tables/ModeratorPieceRow'

// Wire keys: the moderator prompt-pieces table (ChatTableContext::moderatorPromptPieces)
// and its row's entity-ref slot (ChatDbContext::moderatorPromptPieces).
const PIECES_TABLE = 'moderatorPromptPieces'
const PIECES_SLOT = 'moderatorPromptPieces'
const PIECES_PAGE_SIZE = 10

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
 * The server-windowed controller for the moderation prompt-pieces table: search,
 * sort, and paging change the viewport descriptor sent over the connection, and
 * the backend replies a window plus live deltas scoped to the table's
 * (page, tableKey) address. Rows resolve through {@link resolveModeratorPieceRow}.
 */
export const moderatorPiecesTable =
  new TableViewportController<ModeratorPieceRow>({
    resolve: resolveModeratorPieceRow,
    sendViewport: (descriptor) =>
      connection.sendTableViewport(
        PAGE_ADMIN_MODERATOR,
        PIECES_TABLE,
        descriptor,
      ),
    pageSize: PIECES_PAGE_SIZE,
    initialSort: { field: 'id', direction: 'asc' },
  })

const teardown: Array<() => void> = []

/** Bind the table to the connection and request the first window — call on mount. */
export function startModeratorPiecesTable(): void {
  teardown.push(
    bindTableViewport(
      connection,
      scopes,
      { page: PAGE_ADMIN_MODERATOR, tableKey: PIECES_TABLE },
      moderatorPiecesTable,
      // The piece slot is an entity; normalize it under the collection's type so
      // the row resolves through ModeratorPieces.
      { entityTypes: { [PIECES_SLOT]: ModeratorPieces.type } },
    ),
    // Re-request the window whenever the socket (re)connects: the initial request
    // below can run before the connection is open, and a reconnect is a fresh
    // exchange that no longer remembers this connection's window.
    connection.on('state', (state) => {
      if (state === 'connected') {
        moderatorPiecesTable.start()
      }
    }),
  )
  moderatorPiecesTable.start()
}

/** Unbind from the connection — call on unmount. */
export function disposeModeratorPiecesTable(): void {
  for (const off of teardown.splice(0)) {
    off()
  }
}
