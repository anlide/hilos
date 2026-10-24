import { HilosPages, type LazyPageView } from '@hilos/core/base'
import type { Component } from 'vue'
import { expect, it } from 'vitest'
import { hilosAdminViews } from '@hilos/vue/admin-views'
import { HilosDashboardPage } from '@hilos/vue/admin/dashboard'
import { HilosI18nTranslateGroupItemPage } from '@hilos/vue/admin/i18n'
import { HilosMcpSkillsMcpLogsViewPage } from '@hilos/vue/admin/mcp-skills'

it('describes all defaults lazily and keeps contextual pages project-owned', () => {
  const views = hilosAdminViews()
  for (const view of Object.values(views)) {
    expect(view).toMatchObject({
      kind: 'lazy-page',
      load: expect.any(Function),
    })
  }
  for (const key of [
    HilosPages.USERS,
    HilosPages.SETTINGS,
    HilosPages.BACKUP,
    HilosPages.I18N,
    HilosPages.COMMUNICATIONS,
    HilosPages.ANALYTICS,
    HilosPages.ANALYTICS_BROWSER_SESSION,
    HilosPages.ANALYTICS_PERSON,
  ]) {
    expect(views).not.toHaveProperty(key)
  }
})

it('resolves real named public section exports, including nested source sections', async () => {
  const views = hilosAdminViews()
  for (const [key, component] of [
    [HilosPages.DASHBOARD, HilosDashboardPage],
    [HilosPages.I18N_TRANSLATE_GROUP_ITEM, HilosI18nTranslateGroupItemPage],
    [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW, HilosMcpSkillsMcpLogsViewPage],
  ] as const) {
    const descriptor = views[key] as LazyPageView<Component>
    expect(await descriptor.load()).toBe(component)
  }
})
