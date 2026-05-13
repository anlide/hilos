import type { BrowserPageRow } from '@hilos/sdk/types'
import type {
  BotEntity,
  ModeratorPromptPieceEntity,
  ModeratorPromptPieceSection,
  Presence,
} from '@/types/domain'

export const BROWSER_PAGE_ADMIN_MODERATOR = 'subscription_page_admin_moderator'
export const BROWSER_PAGE_ADMIN_BOTS = 'subscription_page_admin_bots'
export const BROWSER_TABLE_MODERATOR_PROMPT_PIECES = 'moderatorPromptPieces'
export const BROWSER_TABLE_BOTS = 'bots'

const BROWSER_SOURCE_BOTS = 'bots'
const BROWSER_SOURCE_BOT_AGENT_STATUSES = 'botAgentStatuses'
const BROWSER_SOURCE_MODERATOR_PROMPT_PIECES = 'moderatorPromptPieces'

export type AdminBotRow = BotEntity & {
  presence: Presence
}

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const rows = (rowsByKey: Record<string, BrowserPageRow> | undefined): BrowserPageRow[] => {
  return rowsByKey === undefined ? [] : Object.values(rowsByKey)
}

const nullableString = (value: unknown): string | null => {
  return typeof value === 'string' ? value : null
}

const isModeratorPromptPieceSection = (value: unknown): value is ModeratorPromptPieceSection => {
  return value === 'name_rule' || value === 'message_rule'
}

const botPresenceFromStatus = (status: unknown): Presence => {
  return status === 'joined' ? 'online' : 'offline'
}

const moderatorPromptPieceFromBrowserRow = (row: BrowserPageRow): ModeratorPromptPieceEntity | null => {
  const piece = row.sources[BROWSER_SOURCE_MODERATOR_PROMPT_PIECES]
  if (
    !isRecord(piece)
    || typeof piece.id !== 'number'
    || !isModeratorPromptPieceSection(piece.section)
    || typeof piece.promptPiece !== 'string'
  ) {
    return null
  }

  return {
    id: piece.id,
    section: piece.section,
    promptPiece: piece.promptPiece,
  }
}

export const moderatorPromptPiecesFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): ModeratorPromptPieceEntity[] => {
  return rows(rowsByKey)
    .map(moderatorPromptPieceFromBrowserRow)
    .filter((piece): piece is ModeratorPromptPieceEntity => piece !== null)
    .sort((a, b) => (a.id ?? 0) - (b.id ?? 0))
}

const adminBotFromBrowserRow = (row: BrowserPageRow): AdminBotRow | null => {
  const bot = row.sources[BROWSER_SOURCE_BOTS]
  if (!isRecord(bot) || typeof bot.id !== 'number' || typeof bot.name !== 'string') {
    return null
  }

  const status = row.sources[BROWSER_SOURCE_BOT_AGENT_STATUSES]

  return {
    id: bot.id,
    name: bot.name,
    description: nullableString(bot.description),
    style: nullableString(bot.style),
    topics: nullableString(bot.topics),
    personality: nullableString(bot.personality),
    active: typeof bot.active === 'boolean' ? bot.active : false,
    presence: isRecord(status) ? botPresenceFromStatus(status.status) : 'offline',
  }
}

export const adminBotsFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): AdminBotRow[] => {
  return rows(rowsByKey)
    .map(adminBotFromBrowserRow)
    .filter((bot): bot is AdminBotRow => bot !== null)
    .sort((a, b) => (a.id ?? 0) - (b.id ?? 0))
}
