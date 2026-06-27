// The chat moderator prompt-piece entity and its collection. A piece carries an
// id, so the wire delivers it as an entity reference in the moderation admin
// table's rows (an id-bearing slot is an entity by convention); the table resolves
// it through this collection, and an edit then fans out to the row for free.
import {
  entityCollection,
  readNumber,
  readString,
  type Entity,
  type EntityCollection,
} from '@hilos/core'

import { scopes } from '../bootstrap/session'

/** The canonical entity type — keep in sync with the backend `moderatorPromptPieces` source. */
export const MODERATOR_PIECE_TYPE = 'moderatorPromptPiece'

/** A moderator prompt piece, projected from the backend source. */
export interface ModeratorPiece extends Entity {
  readonly section: string
  readonly promptPiece: string
}

/**
 * Project a committed piece's raw fields into the typed entity.
 *
 * @param fields The piece entity's committed fields.
 */
export function moderatorPieceFromFields(
  fields: Readonly<Record<string, unknown>>,
): ModeratorPiece {
  return {
    id: readNumber(fields, 'id'),
    section: readString(fields, 'section'),
    promptPiece: readString(fields, 'promptPiece'),
  }
}

/** The piece collection: typed reference resolution for the moderator piece type. */
export const ModeratorPieces: EntityCollection<ModeratorPiece> =
  entityCollection(scopes, MODERATOR_PIECE_TYPE, moderatorPieceFromFields)
