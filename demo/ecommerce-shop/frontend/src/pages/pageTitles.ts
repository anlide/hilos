// The ecommerce-shop demo's document titles: the title shown in the browser tab
// and announced on no-refresh navigation for the demo's own public pages. The
// framework admin and footer pages are titled from their own catalogs
// (@hilos/core). The shop's own admin screens are not listed either: their
// headings arrive with their subscriptions, from the page catalog on the
// backend, and a title written here as well would be the same name in two
// places, one of them untranslatable. bootHilos merges what is here with `appName` into the
// navigator's currentTitle for the tab and the page-change announcement
// (WCAG 2.4.2).
import { PAGE_CATEGORY, PAGE_MAIN } from './keys.js'

/** The application name composed into every document title. */
export const appName = 'Hilos Flowers'

/** Page key → browser-tab title, for the demo's own pages. */
export const pageTitles: Record<string, string> = {
  [PAGE_MAIN]: 'Home',
  [PAGE_CATEGORY]: 'Category',
}
