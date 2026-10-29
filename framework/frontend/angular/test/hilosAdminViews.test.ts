import { HilosPages, type LazyPageView } from '@hilos/core'
import type { Type } from '@angular/core'
import { expect, it } from 'vitest'
import { hilosAdminViews } from '@hilos/angular/admin-views'
import { HilosDashboardPage } from '@hilos/angular/admin/dashboard'
import { HilosI18nTranslateGroupItemPage } from '@hilos/angular/admin/i18n'
import { HilosMcpSkillsMcpLogsViewPage } from '@hilos/angular/admin/mcp-skills'

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
    HilosPages.DAEMON_WEBSOCKETS,
    HilosPages.BACKUP,
    HilosPages.COMMUNICATIONS,
    HilosPages.COMMUNICATIONS_CHANNEL_FRAME,
    HilosPages.ANALYTICS,
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
    const descriptor = views[key] as LazyPageView<Type<unknown>>
    expect(await descriptor.load()).toBe(component)
  }
})

it('loads every default as a real Angular component', async () => {
  const views = Object.values(hilosAdminViews()) as LazyPageView<
    Type<unknown>
  >[]
  const loaded = await Promise.all(views.map((view) => view.load()))
  expect(loaded).toHaveLength(30)
  for (const component of loaded) expect(component).toBeTypeOf('function')
})
