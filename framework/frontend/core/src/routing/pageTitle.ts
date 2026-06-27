// Resolving a page key to the document title shown in the browser tab and
// announced on no-refresh navigation (WCAG 2.4.2 Page Titled). The framework
// owns the labels of its admin pages (HILOS_ADMIN_PAGES) and its public footer
// pages (HILOS_FOOTER_LINKS); a project supplies titles for its own pages
// through bootHilos (pageTitles) plus the application name. This module is the
// read-only merge over those sources, kept DOM-free so the view layer only
// reads the resolved string from the navigator's currentTitle signal.

import { HILOS_ADMIN_PAGES, HILOS_FOOTER_LINKS } from './hilosPages.js'

/**
 * The framework-owned label for a page key: its admin-tree label, or its public
 * footer label, or undefined for a page the framework does not name (a project
 * page, or an error surface).
 *
 * @param pageKey The page key to label.
 */
function frameworkPageLabel(pageKey: string): string | undefined {
  const adminLabel = HILOS_ADMIN_PAGES[pageKey]?.label
  if (adminLabel !== undefined) {
    return adminLabel
  }

  return HILOS_FOOTER_LINKS.find((link) => link.page === pageKey)?.label
}

/**
 * Resolve a page key to its document title. A project title (`pageTitles`) wins
 * over the framework label; either composes with the application name as
 * `"<page> · <app>"`. A page with no label (a project page absent from
 * `pageTitles`, or an error surface) falls back to the application name alone,
 * and an empty application name yields the bare label — so a minimally
 * configured project still gets a non-empty title wherever one is known.
 *
 * @param pageKey The current page key (the navigator's route page).
 * @param pageTitles Project page key → title, for the project's own pages.
 * @param appName The application name composed into every title.
 */
export function resolvePageTitle(
  pageKey: string,
  pageTitles: Record<string, string>,
  appName: string,
): string {
  const label = pageTitles[pageKey] ?? frameworkPageLabel(pageKey)
  if (label === undefined) {
    return appName
  }

  return appName === '' ? label : `${label} · ${appName}`
}
