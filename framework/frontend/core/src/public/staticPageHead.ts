import type { HilosPageCrumb } from '../admin/identity/hilosPageIdentity.js'
import type { HilosPathResolver } from '../routing/hilosAdmin.js'

/**
 * Navigation supplied by a static page for its section tab row.
 *
 * The navigation API works for custom static pages today. Built-in public
 * production mounts intentionally do not pass it until their revision-history
 * and dependency routes land in HIL-1609 and HIL-1611.
 */
export interface HilosStaticPageNavigation {
  /** The page key represented by the current tab. */
  page: string
  /** The allowed tabs in display order, with the owner first. */
  tabs: readonly HilosPageCrumb[]
  /** Resolves framework and project page keys to application paths. */
  resolvePath: HilosPathResolver
  /** Current route params; defaults to an empty object. */
  params?: Record<string, string>
  /** Accessible name of the tab row; defaults to `Page sections`. */
  label?: string
}
