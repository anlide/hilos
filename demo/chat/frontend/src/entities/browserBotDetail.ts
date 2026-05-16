import type { BrowserPageRow } from '@hilos/sdk/types'
import { ChatBot } from '@/types'
import { isBotPayload } from '@/entities/parsers'
import { type Presence } from '@/types/domain/Presence'

export const BROWSER_PAGE_BOT = 'subscription_page_bot'
export const BROWSER_TABLE_BOT_DETAIL = 'botDetail'

const BROWSER_SOURCE_BOTS = 'bots'
const BROWSER_SOURCE_BOT_AGENT_STATUSES = 'botAgentStatuses'

export type BrowserBotDetail = ChatBot & {
  presence: Presence
}

const rows = (rowsByKey: Record<string, BrowserPageRow> | undefined): BrowserPageRow[] => {
  return rowsByKey === undefined ? [] : Object.values(rowsByKey)
}

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const botPresenceFromStatus = (status: unknown): Presence => {
  return status === 'joined' ? 'online' : 'offline'
}

const botDetailFromBrowserRow = (row: BrowserPageRow): BrowserBotDetail | null => {
  const bot = row.sources[BROWSER_SOURCE_BOTS]
  if (!isBotPayload(bot)) {
    return null
  }

  const status = row.sources[BROWSER_SOURCE_BOT_AGENT_STATUSES]
  return Object.assign(ChatBot.fromObject(bot), {
    presence: isRecord(status) ? botPresenceFromStatus(status.status) : 'offline',
  })
}

export const botDetailFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
  botId: number,
): BrowserBotDetail | null => {
  return rows(rowsByKey)
    .map(botDetailFromBrowserRow)
    .find((bot): bot is BrowserBotDetail => bot !== null && bot.id === botId) ?? null
}
