import type { BrowserPageRow } from '@hilos/sdk/types'
import { ChatBot } from '@/types'
import { isBotPayload } from '@/entities/parsers'
import { type Presence } from '@/types/domain/Presence'

export const BROWSER_PAGE_BOT = 'subscription_page_bot'
export const BROWSER_TABLE_BOT_DETAIL = 'botDetail'

const BROWSER_SOURCE_BOTS = 'bots'

export type BrowserBotDetail = ChatBot & {
  presence: Presence
}

const rows = (rowsByKey: Record<string, BrowserPageRow> | undefined): BrowserPageRow[] => {
  return rowsByKey === undefined ? [] : Object.values(rowsByKey)
}

const botDetailFromBrowserRow = (row: BrowserPageRow): BrowserBotDetail | null => {
  const bot = row.sources[BROWSER_SOURCE_BOTS]
  if (!isBotPayload(bot)) {
    return null
  }

  return Object.assign(ChatBot.fromObject(bot), {
    presence: 'offline' as Presence,
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
