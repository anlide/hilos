// The tasks demo's document titles: the title shown in the browser tab and announced
// on no-refresh navigation for the tasks demo's own pages. The framework admin and
// footer pages are titled from their own catalogs (@hilos/core), so only the
// tasks demo's own pages are listed here; bootHilos merges them with `appName` into
// the navigator's currentTitle for the tab and the page-change announcement
// (WCAG 2.4.2).
import { PAGE_MAIN } from './keys'

/** The application name composed into every document title. */
export const appName = 'Hilos Tasks'

/** Tasks page key → browser-tab title, for the tasks demo's own pages. */
export const pageTitles: Record<string, string> = {
  [PAGE_MAIN]: 'Tasks',
}
