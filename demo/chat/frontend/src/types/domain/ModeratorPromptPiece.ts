/**
 * ModeratorPromptPiece - matches ModeratorPromptPiece structure from DB
 *
 * Fields match backend/Database/Object/Item/ModeratorPromptPiece.php:
 * - id: ?int (TypeScript: number | null)
 * - section: 'name_rule' | 'message_rule'
 * - promptPiece: string
 */
export type ModeratorPromptPieceSection = 'name_rule' | 'message_rule'

export interface ModeratorPromptPieceEntity {
  id: number | null
  section: ModeratorPromptPieceSection
  promptPiece: string
}
