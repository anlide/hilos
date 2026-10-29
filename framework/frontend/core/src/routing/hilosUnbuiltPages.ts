// The single registry of admin pages a view layer has not built: the router
// answers 404 not_served and no card links to them. A section stays here until
// that layer builds one of its pages; the frontend carries no copy of the tree.
// A leaf building a page strikes its layer, and the last layer removes the entry.
// UNBUILT-PAGE keeps this honest in both directions. This is unrelated to the
// maintenance mockup's registry of messages announcing work.
// The public file reader PUBLIC_STATUS is also unavailable in unbuilt layers.
import { HilosPages, type HilosPageKey } from './hilosPages.js'

/** The SDK view layers an application can render with. */
export type HilosViewLayer = 'vue' | 'react' | 'angular'

/** Framework pages not yet built, by SDK view layer. */
export const HILOS_UNBUILT_PAGES: Readonly<
  Partial<Record<HilosPageKey, readonly HilosViewLayer[]>>
> = {
  [HilosPages.ROLES]: ['vue', 'react', 'angular'],
  [HilosPages.MODERATION]: ['react', 'angular'],
  [HilosPages.MODERATION_KIND]: ['react', 'angular'],
  [HilosPages.MODERATION_KIND_RULES]: ['react', 'angular'],
  [HilosPages.USER_ROLES]: ['vue', 'react', 'angular'],
  [HilosPages.USER_ACCESS]: ['vue', 'react', 'angular'],
  [HilosPages.USER_SESSIONS]: ['vue', 'react', 'angular'],
  [HilosPages.MAINTENANCE_CIRCLE]: ['react', 'angular'],
  [HilosPages.MAINTENANCE_WORDS]: ['react', 'angular'],
  [HilosPages.MAINTENANCE_NODES]: ['react', 'angular'],
  [HilosPages.STATUS]: ['react', 'angular'],
  [HilosPages.STATUS_COMPONENTS]: ['react', 'angular'],
  [HilosPages.STATUS_SUBSCRIBERS]: ['react', 'angular'],
  [HilosPages.STATUS_WORKS]: ['react', 'angular'],
  [HilosPages.PUBLIC_STATUS]: ['react', 'angular'],
  [HilosPages.ANALYTICS_PEOPLE]: ['react', 'angular'],
  [HilosPages.ANALYTICS_BROWSER_SESSIONS]: ['react', 'angular'],
  [HilosPages.GUARDIAN]: ['vue', 'react', 'angular'],
  [HilosPages.GUARDIAN_AGENT]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_ENTITIES]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_UI_PAGES]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_GROUPS]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_ACTIONS]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_EMAILS]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_UI_PAGE]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_GROUP]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_ACTION]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_TRANSLATE_ENTITY]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_TRANSLATE_UI_PAGE]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_TRANSLATE_UI_PAGE_ITEM]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_TRANSLATE_GROUP]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_TRANSLATE_GROUP_ITEM]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_TRANSLATE_ACTION_ERROR]: ['vue', 'react', 'angular'],
  [HilosPages.I18N_TRANSLATE_EMAIL]: ['vue', 'react', 'angular'],
  [HilosPages.MCP_SKILLS]: ['vue', 'react', 'angular'],
  [HilosPages.MCP_SKILLS_MCP]: ['vue', 'react', 'angular'],
  [HilosPages.MCP_SKILLS_MCP_LOGS]: ['vue', 'react', 'angular'],
  [HilosPages.MCP_SKILLS_MCP_LOGS_VIEW]: ['vue', 'react', 'angular'],
  [HilosPages.SIL]: ['vue', 'react', 'angular'],
  [HilosPages.SIL_REQUESTS]: ['vue', 'react', 'angular'],
  [HilosPages.SIL_USER_HISTORY]: ['vue', 'react', 'angular'],
  [HilosPages.BILLING]: ['vue', 'react', 'angular'],
  [HilosPages.BILLING_PROVIDER]: ['vue', 'react', 'angular'],
  [HilosPages.BILLING_PAYMENTS]: ['vue', 'react', 'angular'],
  [HilosPages.BILLING_REFUNDS]: ['vue', 'react', 'angular'],
}

/**
 * Pages the application's view layer cannot render, minus its own implementations.
 *
 * @param viewLayer The application's SDK view layer.
 * @param projectViews Pages the project implements itself, including their sections.
 */
export function hilosUnbuiltPages(
  viewLayer: HilosViewLayer,
  projectViews: readonly string[] = [],
): ReadonlySet<string> {
  const implemented = new Set(projectViews)

  return new Set(
    Object.entries(HILOS_UNBUILT_PAGES)
      .filter(
        ([page, layers]) =>
          layers.includes(viewLayer) && !implemented.has(page),
      )
      .map(([page]) => page),
  )
}
