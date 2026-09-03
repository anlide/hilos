// The admin page's own identity, read off the page it is standing on: the
// heading, the lead, the breadcrumb chain and the cards of its subsections, plus
// the dashboard's section grouping. None of it is a frontend constant any more —
// a screen's name is text in the visitor's language, and only the backend knows
// the language, so the catalog lives in PHP (Database/Pages/HilosPageCatalog) and
// travels in the `data` section of the page's own page_response.
//
// There is no catalog store: the normalizer lays plain data into the page scope
// as it arrives, and these selectors are the typed read over it. That the scope
// is the page's own is the whole point — navigation drops it, so the previous
// screen's name cannot outlive the screen. Reactivity comes free from the two
// signals underneath (the page scope and the data slot), so a computed over a
// selector recomputes both when the page changes and when its answer lands.

import { readString, readStringOrNull } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** Wire key of the subscribing page's own heading. */
const PAGE_LABEL_KEY = 'pageLabel'

/** Wire key of the subscribing page's own lead. */
const PAGE_LEAD_KEY = 'pageLead'

/** Wire key of the breadcrumb chain, root first, this page last. */
const PAGE_BREADCRUMB_KEY = 'pageBreadcrumb'

/** Wire key of this page's subsection cards, empty on a leaf. */
const PAGE_CHILDREN_KEY = 'pageChildren'

/** Wire key of the dashboard's section grouping; the dashboard alone is sent it. */
const DASHBOARD_SECTIONS_KEY = 'dashboardSections'

/** Element key of the page a crumb, a card or a section item points at. */
const ENTRY_PAGE_KEY = 'page'

/** Element key of the visible caption of a crumb, a card or a section item. */
const ENTRY_LABEL_KEY = 'label'

/** Element key of the one-line description under a card's caption. */
const ENTRY_LEAD_KEY = 'lead'

/** Element key of a card's Bootstrap icon name (`bi-*`). */
const ENTRY_ICON_KEY = 'icon'

/** Section key of the group heading. */
const SECTION_TITLE_KEY = 'title'

/** Section key of the one-line group description. */
const SECTION_DESCRIPTION_KEY = 'description'

/** Section key of the cards the group holds, in display order. */
const SECTION_ITEMS_KEY = 'items'

/** One link of the breadcrumb: the page it names and the caption it shows. */
export interface HilosPageCrumb {
  /** The page key the crumb points at; the URL is built from it. */
  page: string
  /** The visible caption. */
  label: string
}

/** One navigation card: a page, its caption, its lead, and its icon where it has one. */
export interface HilosPageChild extends HilosPageCrumb {
  /** The card's one-line description. */
  lead: string
  /** Bootstrap icon name (`bi-*`), or null for a card the catalog gives none. */
  icon: string | null
}

/** Everything a page knows about itself: what it is called and where it sits. */
export interface HilosPageIdentity {
  /** The heading, also the last breadcrumb caption. */
  label: string
  /** The one-line description under the heading. */
  lead: string
  /** The chain from the tree root down to this page, this page last. */
  breadcrumb: HilosPageCrumb[]
  /** The cards of this page's subsections, empty on a leaf. */
  children: HilosPageChild[]
}

/** A labelled group of cards on the admin dashboard. */
export interface HilosDashboardSection {
  /** Group heading. */
  title: string
  /** One-line group description. */
  description: string
  /** The cards of this group, in display order. */
  items: HilosPageChild[]
}

/**
 * Read one wire element as a record, or null when it is not one — a payload the
 * parse boundary already accepted still reaches here as `unknown`, and a list
 * element of the wrong shape is dropped rather than half-read.
 *
 * @param element One element of a wire list.
 */
function asRecord(element: unknown): Record<string, unknown> | null {
  return typeof element === 'object' && element !== null
    ? (element as Record<string, unknown>)
    : null
}

/**
 * Read a list of crumbs off a wire value; an absent or non-list value reads as
 * the empty chain.
 *
 * @param value The raw value under the breadcrumb key.
 */
function readCrumbs(value: unknown): HilosPageCrumb[] {
  if (!Array.isArray(value)) {
    return []
  }

  return value.flatMap((element) => {
    const fields = asRecord(element)

    return fields === null
      ? []
      : [
          {
            page: readString(fields, ENTRY_PAGE_KEY),
            label: readString(fields, ENTRY_LABEL_KEY),
          },
        ]
  })
}

/**
 * Read a list of navigation cards off a wire value; an absent or non-list value
 * reads as no cards.
 *
 * @param value The raw value under a children or section-items key.
 */
function readChildren(value: unknown): HilosPageChild[] {
  if (!Array.isArray(value)) {
    return []
  }

  return value.flatMap((element) => {
    const fields = asRecord(element)

    return fields === null
      ? []
      : [
          {
            page: readString(fields, ENTRY_PAGE_KEY),
            label: readString(fields, ENTRY_LABEL_KEY),
            lead: readString(fields, ENTRY_LEAD_KEY),
            icon: readStringOrNull(fields, ENTRY_ICON_KEY),
          },
        ]
  })
}

/**
 * The identity of the page currently subscribed, or `undefined` while its answer
 * is still on the wire — which is the state the shell draws its skeleton for.
 * `undefined` also stands for a page the catalog carries no entry for: the shell
 * draws neither a heading nor a breadcrumb rather than printing the raw page key.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function pageIdentity(
  scopes: ScopeManager,
): ReadonlySignal<HilosPageIdentity | undefined> {
  const label = scopes.pageDataSignal(PAGE_LABEL_KEY)
  const lead = scopes.pageDataSignal(PAGE_LEAD_KEY)
  const breadcrumb = scopes.pageDataSignal(PAGE_BREADCRUMB_KEY)
  const children = scopes.pageDataSignal(PAGE_CHILDREN_KEY)

  return computedSignal<HilosPageIdentity | undefined>(() => {
    const heading = label.get()
    // The heading is what says an entry arrived: a page the catalog knows never
    // sends it empty, and a page it does not know sends none of the four keys.
    if (typeof heading !== 'string') {
      return undefined
    }
    const description = lead.get()

    return {
      label: heading,
      lead: typeof description === 'string' ? description : '',
      breadcrumb: readCrumbs(breadcrumb.get()),
      children: readChildren(children.get()),
    }
  })
}

/**
 * The dashboard's section grouping, or `undefined` until the dashboard's own
 * answer lands. Only the dashboard is sent it, so every other page reads
 * `undefined` for the whole of its life.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function dashboardSections(
  scopes: ScopeManager,
): ReadonlySignal<HilosDashboardSection[] | undefined> {
  const sections = scopes.pageDataSignal(DASHBOARD_SECTIONS_KEY)

  return computedSignal<HilosDashboardSection[] | undefined>(() => {
    const value = sections.get()
    if (!Array.isArray(value)) {
      return undefined
    }

    return value.flatMap((element) => {
      const fields = asRecord(element)

      return fields === null
        ? []
        : [
            {
              title: readString(fields, SECTION_TITLE_KEY),
              description: readString(fields, SECTION_DESCRIPTION_KEY),
              items: readChildren(fields[SECTION_ITEMS_KEY]),
            },
          ]
    })
  })
}
