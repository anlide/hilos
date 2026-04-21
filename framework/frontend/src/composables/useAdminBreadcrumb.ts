import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { usePageCatalogStore } from '../stores/usePageCatalogStore'
import { pathTemplateParamKeys } from '../types/pageCatalog'
import type { PageCatalogEntry } from '../types/pageCatalog'
import { guardianAiAgentMap, isGuardianAiAgentId } from '../constants/guardianAiAgents'
import { HilosPageRouteParams } from '../constants/hilosPageRouteParams'

type BreadcrumbItem = {
  label: string
  to?: string | null
  isActive?: boolean
}

export type DynamicLabelResolver = (pageId: string, routeParams: Record<string, unknown>) => string | null

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

// TODO: replace with i18n-driven labels from backend
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
    case 'hilos_change_log_table': {
      const id = s('tableId')
      return id !== null ? `Table ${id}` : null
    }
    case 'hilos_user': {
      const id = s(HilosPageRouteParams.HILOS_USER_USER_ID)
      return id !== null ? `User ${id}` : null
    }
    case 'hilos_mcp_skills_mcp': {
      const id = s('mcpId')
      return id !== null ? `MCP ${id}` : null
    }
    case 'hilos_mcp_skills_mcp_logs': {
      const id = s('mcpId')
      return id !== null ? `MCP ${id} · overview` : null
    }
    case 'hilos_mcp_skills_mcp_logs_view': {
      const id = s('mcpId')
      return id !== null ? `MCP ${id} · viewer` : null
    }
    case 'hilos_sil_user_history': {
      const id = s(HilosPageRouteParams.HILOS_SIL_USER_HISTORY_USER_ID)
      return id !== null ? `SIL user ${id}` : null
    }
    case 'hilos_communications_channel': {
      const id = s('channelId')
      return id !== null ? `Channel ${id}` : null
    }
    case 'hilos_communications_deliveries': {
      const id = s('channelId')
      return id !== null ? `Deliveries · ${id}` : null
    }
    case 'hilos_security_oauth_provider': {
      const id = s('providerId')
      return id !== null ? `OAuth ${id}` : null
    }
    case 'hilos_billing_provider': {
      const id = s('providerId')
      return id !== null ? `Config · ${id}` : null
    }
    case 'hilos_billing_payments': {
      const id = s('providerId')
      return id !== null ? `Payments · ${id}` : null
    }
    case 'hilos_billing_refunds': {
      const id = s('providerId')
      return id !== null ? `Refunds · ${id}` : null
    }
    default:
      return null
  }
}

const resolveLabel = (
  pageId: string,
  routeParams: Record<string, unknown>,
  projectResolver?: DynamicLabelResolver,
): string | null => {
  if (pageId === 'hilos_guardian_agent') {
    const agentId = routeParams.agentId
    if (typeof agentId === 'string' && isGuardianAiAgentId(agentId)) {
      return guardianAiAgentMap[agentId].title
    }
    return 'Agent not found'
  }

  if (projectResolver) {
    const projectLabel = projectResolver(pageId, routeParams)
    if (projectLabel !== null) {
      return projectLabel
    }
  }

  return resolveCatalogStubDynamicLabel(pageId, routeParams)
}

/**
 * Breadcrumb composable driven by the backend page catalog.
 * @param options.resolveDynamicLabel Project-specific label resolver (e.g. user/bot names from store)
 */
export const useAdminBreadcrumb = (options?: { resolveDynamicLabel?: DynamicLabelResolver }) => {
  const route = useRoute()
  const pageCatalogStore = usePageCatalogStore()
  const currentPageId = computed(() => {
    return typeof route.meta.page === 'string' ? route.meta.page : null
  })

  const shouldShowAdminBreadcrumb = computed(() => {
    if (!currentPageId.value) {
      return false
    }

    if (!pageCatalogStore.hasPageCatalog) {
      return true
    }

    const currentEntry = pageCatalogStore.pageCatalog[currentPageId.value]
    return currentEntry !== undefined && !currentEntry.hideBreadcrumb
  })

  const items = computed<BreadcrumbItem[]>(() => {
    if (!currentPageId.value || !pageCatalogStore.hasPageCatalog) {
      return []
    }

    const currentEntry = pageCatalogStore.pageCatalog[currentPageId.value]
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
      cursor = pageCatalogStore.pageCatalog[cursor]?.parentId ?? null
    }

    return chain.map((pageId, index) => {
      const entry = pageCatalogStore.pageCatalog[pageId]
      const isActive = index === chain.length - 1
      const dynamicLabel = resolveLabel(pageId, routeParams, options?.resolveDynamicLabel)

      return {
        label: dynamicLabel ?? entry?.label ?? pageId,
        to: isActive ? null : buildPathFromCatalog(pageCatalogStore.pageCatalog[pageId], routeParams),
        isActive,
      }
    })
  })

  const showPlaceholder = computed(() => {
    return currentPageId.value !== null && !pageCatalogStore.hasPageCatalog
  })

  return {
    items,
    showPlaceholder,
    shouldShowAdminBreadcrumb,
  }
}
