import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useChatStore } from '@/stores'
import { guardianAiAgentMap, isGuardianAiAgentId } from '@/constants/guardianAiAgents'

type BreadcrumbItem = {
  label: string
  to?: string | null
  isActive?: boolean
}

const parsePositiveInt = (value: unknown): number | null => {
  const normalized = typeof value === 'string' ? Number.parseInt(value, 10) : Number(value)

  if (!Number.isFinite(normalized) || normalized <= 0) {
    return null
  }

  return normalized
}

const buildPageLocation = (pageId: string, routeParams: Record<string, unknown>): string | null => {
  switch (pageId) {
    case 'main':
      return '/'
    case 'profile':
      return '/profile'
    case 'admin':
      return '/admin'
    case 'admin_users':
      return '/admin/users'
    case 'admin_moderator':
      return '/admin/moderator'
    case 'admin_bots':
      return '/admin/bots'
    case 'user': {
      const id = routeParams.id
      return typeof id === 'string' || typeof id === 'number' ? `/user/${id}` : null
    }
    case 'bot': {
      const id = routeParams.id
      return typeof id === 'string' || typeof id === 'number' ? `/bot/${id}` : null
    }
    case 'hilos':
      return '/hilos'
    case 'hilos_settings':
      return '/hilos/settings'
    case 'hilos_i18n':
      return '/hilos/i18n'
    case 'hilos_guardian':
      return '/hilos/guardian'
    case 'hilos_guardian_agent': {
      const agentId = routeParams.agentId
      return typeof agentId === 'string' ? `/hilos/guardian/${agentId}` : null
    }
    case 'hilos_analytics':
      return '/hilos/analytics'
    default:
      return null
  }
}

const resolveDynamicLabel = (
  pageId: string,
  routeParams: Record<string, unknown>,
  chatStore: ReturnType<typeof useChatStore>
): string | null => {
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
    case 'hilos_guardian_agent': {
      const agentId = routeParams.agentId
      if (typeof agentId !== 'string' || !isGuardianAiAgentId(agentId)) {
        return 'Agent not found'
      }

      return guardianAiAgentMap[agentId].title
    }
    default:
      return null
  }
}

export const useAdminBreadcrumb = () => {
  const route = useRoute()
  const chatStore = useChatStore()
  const currentPageId = computed(() => {
    return typeof route.meta.page === 'string' ? route.meta.page : null
  })

  const shouldShowAdminBreadcrumb = computed(() => {
    if (!currentPageId.value) {
      return false
    }

    if (!chatStore.hasPageCatalog) {
      return true
    }

    const currentEntry = chatStore.pageCatalog[currentPageId.value]
    return currentEntry !== undefined && !currentEntry.hideBreadcrumb
  })

  const items = computed<BreadcrumbItem[]>(() => {
    if (!currentPageId.value || !chatStore.hasPageCatalog) {
      return []
    }

    const currentEntry = chatStore.pageCatalog[currentPageId.value]
    if (!currentEntry || currentEntry.hideBreadcrumb) {
      return []
    }

    const routeParams = route.params as Record<string, unknown>
    const visited = new Set<string>()
    const chain: string[] = []
    let cursor: string | null = currentPageId.value

    while (cursor && !visited.has(cursor)) {
      visited.add(cursor)
      chain.unshift(cursor)
      cursor = chatStore.pageCatalog[cursor]?.parentId ?? null
    }

    return chain.map((pageId, index) => {
      const entry = chatStore.pageCatalog[pageId]
      const isActive = index === chain.length - 1
      const dynamicLabel = resolveDynamicLabel(pageId, routeParams, chatStore)

      return {
        label: dynamicLabel ?? entry?.label ?? pageId,
        to: isActive ? null : buildPageLocation(pageId, routeParams),
        isActive,
      }
    })
  })

  const showPlaceholder = computed(() => {
    return currentPageId.value !== null && !chatStore.hasPageCatalog
  })

  return {
    items,
    showPlaceholder,
    shouldShowAdminBreadcrumb,
  }
}
