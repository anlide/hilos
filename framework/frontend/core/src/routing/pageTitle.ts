// Resolving a page to the document title shown in the browser tab and announced
// on no-refresh navigation (WCAG 2.4.2 Page Titled). The sources are merged
// in this order: a project's own titles (bootHilos `pageTitles`), the label of a
// public footer page, the shared owner's label and the subscribing page's
// catalog heading. This module is the read-only merge over them, kept
// DOM-free so the view layer only reads the resolved string from the navigator's
// currentTitle signal.

import { HILOS_FOOTER_LINKS } from './hilosPages.js'
import { ROUTE_NOT_FOUND_ERROR } from './pageError.js'

/**
 * The framework-owned label for a public footer page, or undefined for any other
 * page. The footer pages are the one set whose labels stay on the frontend: they
 * are not in the admin catalog by design, so nothing sends their names over the
 * wire.
 *
 * @param pageKey The page key to label.
 */
function frameworkPageLabel(pageKey: string): string | undefined {
  return HILOS_FOOTER_LINKS.find((link) => link.page === pageKey)?.label
}

/**
 * Resolve a page to its document title. A project title (`pageTitles`) wins over
 * a footer label, which wins over the owner label and then the catalog heading;
 * whichever is found composes with the application name as `"<page> · <app>"`,
 * and an empty application name yields the bare label.
 *
 * While the page has not answered, a page with no project title resolves to the
 * EMPTY string rather than to the application name. That is load-bearing, not a
 * gap: the shell sets `document.title` only on a non-empty value and renders the
 * same value in an aria-live region, so an interim application name would
 * announce every navigation twice — once as the app, once as the page.
 *
 * A page that HAS answered and still has no label falls back to the application
 * name, which is the title an error surface and a project page outside the
 * catalog get.
 *
 * A route miss (`pageKey` null) is named before any of those lookups, from the
 * local route-404 title, and does not wait for a page answer. An unknown page
 * key that is still a string is not given that title: only a real miss is.
 *
 * @param pageKey The current page key, or null when the address matches no page.
 * @param pageTitles Project page key → title, for the project's own pages.
 * @param appName The application name composed into every title.
 * @param catalogLabel The heading the page's own answer carried, if it has come.
 * @param pageAnswered Whether that answer has landed, either payload or error.
 * @param ownerLabel The shared head owner's label, including its live name.
 */
export function resolvePageTitle(
  pageKey: string | null,
  pageTitles: Record<string, string>,
  appName: string,
  catalogLabel: string | undefined,
  pageAnswered: boolean,
  ownerLabel?: string,
): string {
  if (pageKey === null) {
    const label = ROUTE_NOT_FOUND_ERROR.title

    return appName === '' ? label : `${label} · ${appName}`
  }
  const label =
    pageTitles[pageKey] ??
    frameworkPageLabel(pageKey) ??
    ownerLabel ??
    catalogLabel
  if (label === undefined) {
    return pageAnswered ? appName : ''
  }

  return appName === '' ? label : `${label} · ${appName}`
}
