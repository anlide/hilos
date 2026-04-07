import { useAdminBreadcrumb as useFrameworkBreadcrumb } from '@hilos/sdk/composables'
import { useChatStore } from '@/stores'

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
  const chatStore = useChatStore()

  switch (pageId) {
    case 'user': {
      const userId = parsePositiveInt(routeParams.id)
      if (userId === null) {
        return 'User not found'
      }
      const user = chatStore.users.find((item) => item.id === userId)
      return user?.name ?? 'User not found'
    }
    case 'bot': {
      const botId = parsePositiveInt(routeParams.id)
      if (botId === null) {
        return 'Bot not found'
      }
      const bot = chatStore.bots.find((item) => item.id === botId)
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
