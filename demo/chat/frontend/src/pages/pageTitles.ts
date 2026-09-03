// The chat's document titles: the title shown in the browser tab and announced
// on no-refresh navigation for the chat's own public pages. An admin screen is
// not listed: its heading arrives with its subscription, from the page catalog on
// the backend, and a title written here as well would be the same name in two
// places, one of them untranslatable. bootHilos merges what is here with
// `appName` into the navigator's currentTitle for the tab and the page-change
// announcement (WCAG 2.4.2).
import { PAGE_BOT, PAGE_MAIN, PAGE_USER } from './keys'

/** The application name composed into every document title. */
export const appName = 'Hilos Chat'

/** Chat page key → browser-tab title, for the chat's own public pages. */
export const pageTitles: Record<string, string> = {
  [PAGE_MAIN]: 'Conversations',
  [PAGE_USER]: 'User',
  [PAGE_BOT]: 'Bot',
}
