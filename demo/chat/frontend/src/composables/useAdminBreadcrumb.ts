import { useAdminBreadcrumb as useFrameworkBreadcrumb } from '@hilos/sdk/composables'
import { useBrowserStore } from '@hilos/sdk/stores'
import { SUBSCRIPTION_PAGE_USER } from '@/constants'
import {
  BROWSER_PAGE_BOT,
  BROWSER_TABLE_BOT_DETAIL,
  botDetailFromBrowserRows,
} from '@/entities/browserBotDetail'
import {
  BROWSER_TABLE_USER_DETAIL,
  userDetailFromBrowserRow,
} from '@/entities/browserUserDetail'

const parsePositiveInt = (value: unknown): number | null => {
  const normalized = typeof value === 'string' ? Number.parseInt(value, 10) : Number(value)
  if (!Number.isFinite(normalized) || normalized <= 0) {
    return null
  }
  return normalized
}

/**
 * Demo-specific dynamic label resolver for chat entities (user/bot pages).
 */
const resolveChatDynamicLabel = (pageId: string, routeParams: Record<string, unknown>): string | null => {
  const browserStore = useBrowserStore()

  switch (pageId) {
    case 'user': {
      const userId = parsePositiveInt(routeParams.id)
      if (userId === null) {
        return 'User not found'
      }
      const row = browserStore.pages[SUBSCRIPTION_PAGE_USER]
        ?.tables[BROWSER_TABLE_USER_DETAIL]
        ?.rowsByKey[String(userId)]
      const user = userDetailFromBrowserRow(row)
      return user?.name ?? 'User not found'
    }
    case 'bot': {
      const botId = parsePositiveInt(routeParams.id)
      if (botId === null) {
        return 'Bot not found'
      }
      const bot = botDetailFromBrowserRows(
        browserStore.pages[BROWSER_PAGE_BOT]
          ?.tables[BROWSER_TABLE_BOT_DETAIL]
          ?.rowsByKey,
        botId,
      )
      return bot?.name ?? 'Bot not found'
    }
    default:
      return null
  }
}

export const useAdminBreadcrumb = () => {
  return useFrameworkBreadcrumb({
    resolveDynamicLabel: resolveChatDynamicLabel,
  })
}
