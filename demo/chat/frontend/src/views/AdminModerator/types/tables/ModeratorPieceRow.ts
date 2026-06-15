// The moderation admin table row view-model: one moderator prompt piece as the
// admin table renders it. The backend table carries the piece fields in a single
// inline `moderatorPromptPieces` slot (no entity reference — a piece is
// page-scoped, keyed by its id); the selector that resolves it lives with the
// page (adminModeratorPage.ts). (data-model.md — table-row view-models live in
// types/tables/.)

/** The moderation rule section a prompt piece belongs to. */
export type ModeratorSection = 'name_rule' | 'message_rule'

/** One row of the moderation prompt-pieces table. */
export interface ModeratorPieceRow {
  /** Piece id; also the table row key. */
  readonly id: number
  /** Which moderation rule the piece contributes to. */
  readonly section: ModeratorSection
  /** The prompt text contributed to the section's rule. */
  readonly promptPiece: string
}
