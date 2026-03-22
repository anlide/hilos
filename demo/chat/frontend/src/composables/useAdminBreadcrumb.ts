import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useChatStore } from '@/stores'
import { guardianAiAgentMap, isGuardianAiAgentId } from '@/constants/guardianAiAgents'
import { pathTemplateParamKeys, type PageCatalogEntry, type PageCatalogState } from '@/types/PageCatalog'

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

/**
 * Build URL from backend page catalog path_template; param names come from {placeholders}.
 */
const buildPathFromCatalog = (
  entry: PageCatalogEntry | undefined,
  routeParams: Record<string, unknown>
): string | null => {
  if (!entry?.pathTemplate) {
    return null
  }
  let path = entry.pathTemplate
  for (const key of pathTemplateParamKeys(entry.pathTemplate)) {
    const raw = routeParams[key]
    if (typeof raw !== 'string' || raw === '') {
      return null
    }
    path = path.split(`{${key}}`).join(encodeURIComponent(raw))
  }
  return path
}

/**
 * Stub dynamic segment labels for breadcrumb (until API-backed titles exist).
 * Centralized; replace with catalog-driven data later if needed.
 */
const resolveCatalogStubDynamicLabel = (pageId: string, routeParams: Record<string, unknown>): string | null => {
  const s = (key: string): string | null => {
    const v = routeParams[key]
    return typeof v === 'string' && v !== '' ? v : null
  }

  switch (pageId) {
    case 'hilos_i18n_language': {
      const id = s('languageId')
      return id !== null ? `Language ${id}` : null
    }
    case 'hilos_i18n_country': {
      const id = s('countryId')
      return id !== null ? `Country ${id}` : null
    }
    case 'hilos_i18n_ui_page': {
      const id = s('uiPageId')
      return id !== null ? `UI page ${id}` : null
    }
    case 'hilos_i18n_group': {
      const id = s('groupId')
      return id !== null ? `Group ${id}` : null
    }
    case 'hilos_i18n_action': {
      const id = s('actionId')
      return id !== null ? `Action ${id}` : null
    }
    case 'hilos_i18n_translate_entity': {
      const id = s('entityId')
      return id !== null ? `Translate entity ${id}` : null
    }
    case 'hilos_i18n_translate_ui_page': {
      const id = s('uiPageId')
      return id !== null ? `Translate UI page ${id}` : null
    }
    case 'hilos_i18n_translate_ui_page_item': {
      const uiPageId = s('uiPageId')
      const itemId = s('itemId')
      if (uiPageId !== null && itemId !== null) {
        return `UI page item ${uiPageId} / ${itemId}`
      }
      return null
    }
    case 'hilos_i18n_translate_group': {
      const id = s('groupId')
      return id !== null ? `Translate group ${id}` : null
    }
    case 'hilos_i18n_translate_group_item': {
      const groupId = s('groupId')
      const itemId = s('itemId')
      if (groupId !== null && itemId !== null) {
        return `Group item ${groupId} / ${itemId}`
      }
      return null
    }
    case 'hilos_i18n_translate_action_error': {
      const actionId = s('actionId')
      const errorId = s('errorId')
      if (actionId !== null && errorId !== null) {
        return `Action error ${actionId} / ${errorId}`
      }
      return null
    }
    case 'hilos_i18n_translate_email': {
      const id = s('emailId')
      return id !== null ? `Translate email ${id}` : null
    }
    case 'hilos_daemon_http_server': {
      const id = s('serverId')
      return id !== null ? `HTTP server ${id}` : null
    }
    case 'hilos_user': {
      const id = s('userId')
      return id !== null ? `User ${id}` : null
    }
    default:
      return null
  }
}

const buildPageLocation = (
  pageId: string,
  routeParams: Record<string, unknown>,
  catalog: PageCatalogState
): string | null => {
  const fromCatalog = buildPathFromCatalog(catalog[pageId], routeParams)
  if (fromCatalog !== null) {
    return fromCatalog
  }

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
    case 'hilos_backup':
      return '/hilos/backup'
    case 'hilos_daemon':
      return '/hilos/daemon'
    case 'hilos_daemon_workers':
      return '/hilos/daemon/workers'
    case 'hilos_daemon_agents':
      return '/hilos/daemon/agents'
    case 'hilos_daemon_cron':
      return '/hilos/daemon/cron'
    case 'hilos_daemon_websockets':
      return '/hilos/daemon/websockets'
    case 'hilos_daemon_http_server': {
      const serverId = routeParams.serverId
      return typeof serverId === 'string' ? `/hilos/daemon/http/${encodeURIComponent(serverId)}` : null
    }
    case 'hilos_logs':
      return '/hilos/logs'
    case 'hilos_logs_keys':
      return '/hilos/logs/keys'
    case 'hilos_logs_workers':
      return '/hilos/logs/workers'
    case 'hilos_logs_rotations':
      return '/hilos/logs/rotations'
    case 'hilos_logs_view':
      return '/hilos/logs/view'
    case 'hilos_operations':
      return '/hilos/operations'
    case 'hilos_users':
      return '/hilos/users'
    case 'hilos_roles':
      return '/hilos/roles'
    case 'hilos_user': {
      const userId = routeParams.userId
      return typeof userId === 'string' ? `/hilos/users/${encodeURIComponent(userId)}` : null
    }
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
      return resolveCatalogStubDynamicLabel(pageId, routeParams)
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
        to: isActive ? null : buildPageLocation(pageId, routeParams, chatStore.pageCatalog),
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
