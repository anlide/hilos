// Resolving a page to the document title shown in the browser tab and announced
// on no-refresh navigation (WCAG 2.4.2 Page Titled). The sources are three and
// in this order: a project's own titles (bootHilos `pageTitles`), the label of a
// public footer page, and the heading the backend catalog sent with the page's
// subscription answer. This module is the read-only merge over them, kept
// DOM-free so the view layer only reads the resolved string from the navigator's
// currentTitle signal.

import { HILOS_FOOTER_LINKS } from './hilosPages.js'

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
 * a footer label, which wins over the catalog heading; whichever is found
 * composes with the application name as `"<page> · <app>"`, and an empty
 * application name yields the bare label.
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
 * @param pageKey The current page key (the navigator's route page).
 * @param pageTitles Project page key → title, for the project's own pages.
 * @param appName The application name composed into every title.
 * @param catalogLabel The heading the page's own answer carried, if it has come.
 * @param pageAnswered Whether that answer has landed, either payload or error.
 */
export function resolvePageTitle(
  pageKey: string,
  pageTitles: Record<string, string>,
  appName: string,
  catalogLabel: string | undefined,
  pageAnswered: boolean,
): string {
  const label =
    pageTitles[pageKey] ?? frameworkPageLabel(pageKey) ?? catalogLabel
  if (label === undefined) {
    return pageAnswered ? appName : ''
  }

  return appName === '' ? label : `${label} · ${appName}`
}
