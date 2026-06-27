// The chat's document titles: the title shown in the browser tab and announced
// on no-refresh navigation for the chat's own pages. The framework admin and
// footer pages are titled from their own catalogs (@hilos/core), so only the
// chat's own pages are listed here; bootHilos merges them with `appName` into
// the navigator's currentTitle for the tab and the page-change announcement
// (WCAG 2.4.2).
import {
  PAGE_ADMIN_BOTS,
  PAGE_ADMIN_MODERATOR,
  PAGE_ADMIN_USERS,
  PAGE_BOT,
  PAGE_MAIN,
  PAGE_USER,
} from './keys'

/** The application name composed into every document title. */
export const appName = 'Hilos Chat'

/** Chat page key → browser-tab title, for the chat's own pages. */
export const pageTitles: Record<string, string> = {
  [PAGE_MAIN]: 'Conversations',
  [PAGE_USER]: 'User',
  [PAGE_BOT]: 'Bot',
  [PAGE_ADMIN_USERS]: 'Users',
  [PAGE_ADMIN_MODERATOR]: 'Prompt pieces',
  [PAGE_ADMIN_BOTS]: 'Bots',
}
