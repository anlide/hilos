// HilosPageHead — the shared head of a page whose owner opted in: the
// breadcrumb, the owner's block (a section head or an entity block) with an
// optional transition button on its right, and the owner's one row of page tabs
// (docs/agents/architecture/page-head.md). It is page-agnostic: it reads the
// identity the page answered with and nothing else, so it knows no concrete
// page, and the page module hands it the block's content through its props.
//
// Only the block's content is the caller's. The title is not: the one h1 shows
// the owner's label, the live instance name already laid into it by the core,
// and it carries the id the caller hands in (a table without a title of its own
// takes its accessible name from it). A section draws its catalog icon in a
// circle and its lead by default; an entity draws what its page supplies. An
// explicit null turns a default off, an omitted prop keeps it. The only button
// is the one transition `action` names — no free buttons, no edit or delete here.
//
// The breadcrumb is drawn only from two links up and the row only from two tabs
// up, counted after the route map has dropped the tabs this application has no
// address for. The current tab is the one whose page key is the rendered page:
// not a label, a path prefix or a position, so a child of a tab marks itself and
// not its parent. Every tab passes its aria-current explicitly, so the link's own
// path match cannot mark another tab while the router is already elsewhere.
//
// Until the answer arrives — or an identity without an owner, which is not a
// head this component draws — only a skeleton stands: no crumbs, no row, no
// name, no block content, no button. Its bars are decorative; the routed outlet
// announces the wait once for the whole page.
//
// On a narrow screen the row scrolls sideways and the current tab is centered in
// the row's scroll limits: once after mounting, when the current key or the
// row's composition changes, and when the row's box is resized — never on
// another render, so a manual scroll or a rename does not snap it back.
// Bootstrap classes and the SDK's own utilities only (styling-rules.md).
import { useId, useLayoutEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import { hilosCrumbLinks, hilosTabLinks } from '@hilos/core/base'
import type {
  HilosPageHeadAction,
  HilosPageIdentity,
  HilosPathResolver,
} from '@hilos/core/base'

import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosLink } from './HilosLink.js'

/** Props for {@link HilosPageHead}. */
export interface HilosPageHeadProps {
  /** What the page answered with; absent while the answer is on the wire. */
  identity?: HilosPageIdentity
  /** The key of the rendered page; the tab carrying it is current. */
  page: string
  /** The current route params every link is resolved in. */
  params: Record<string, string>
  /** Resolves a page key to its path (`HilosRouter.resolvePath`). */
  resolvePath: HilosPathResolver
  /** The id the h1 carries, from the caller; a local one when omitted. */
  headingId?: string
  /** A section head or an entity block: the defaults and the skeleton's tile. */
  kind?: 'section' | 'entity'
  /** The breadcrumb navigation's accessible name. */
  breadcrumbLabel?: string
  /** The tab row navigation's accessible name, named after the owner. */
  tabsLabel?: string
  /** The one transition the head offers as a button. */
  action?: HilosPageHeadAction
  /** The block's picture: an entity's tile or avatar; a section's icon by default. */
  visual?: ReactNode
  /** A short mark beside the title, such as the entity's code. */
  meta?: ReactNode
  /** Status badges beside the title. */
  badges?: ReactNode
  /** The line under the title; a section's lead by default. */
  description?: ReactNode
}

/**
 * The shared page head: breadcrumb, the owner's block and its tab row, or a
 * silent skeleton until the page has answered with an owner.
 *
 * @param props The page's identity, its key and route context, and the block content.
 */
export function HilosPageHead({
  identity,
  page,
  params,
  resolvePath,
  headingId,
  kind = 'section',
  breadcrumbLabel = 'Breadcrumb',
  tabsLabel = 'Page sections',
  action,
  visual,
  meta,
  badges,
  description,
}: HilosPageHeadProps) {
  const localHeadingId = useId()
  const row = useRef<HTMLUListElement>(null)

  const owner = identity?.pageHeadOwner
  const crumbs =
    owner === undefined
      ? []
      : hilosCrumbLinks(identity?.breadcrumb ?? [], params, resolvePath)
  const tabs =
    owner === undefined
      ? []
      : hilosTabLinks(identity?.pageTabs ?? [], params, resolvePath)
  const actionTo =
    action === undefined
      ? undefined
      : resolvePath(action.page, action.params ?? params)
  // The keys alone, so a new caption or a rename that leaves the row as it was
  // does not re-center it over a scroll the reader made by hand.
  const rowKeys = tabs.map((tab) => tab.page).join('\n')
  const rowShown = tabs.length > 1

  useLayoutEffect(() => {
    centerCurrentTab(row.current)
  }, [page, rowKeys])

  // The observer follows the row node itself: it is attached when the row is
  // drawn and let go when the row disappears or the head unmounts.
  useLayoutEffect(() => {
    const container = row.current
    if (
      !rowShown ||
      container === null ||
      typeof ResizeObserver === 'undefined'
    ) {
      return
    }
    const observer = new ResizeObserver(() => centerCurrentTab(container))
    observer.observe(container)

    return () => observer.disconnect()
  }, [rowShown])

  if (owner === undefined) {
    return (
      <div className="min-w-0" data-id="hilos-page-head">
        <div
          className="d-flex align-items-start gap-3 mb-4 placeholder-glow"
          aria-hidden="true"
          data-id="hilos-page-head-skeleton"
        >
          <span
            className={`hilos-avatar-md bg-secondary-subtle flex-shrink-0 ${
              kind === 'entity' ? 'rounded-3' : 'rounded-circle'
            }`}
          />
          <div className="flex-grow-1 min-w-0">
            <span className="placeholder placeholder-lg col-4 d-block mb-2 rounded" />
            <span className="placeholder col-9 d-block rounded" />
          </div>
        </div>
      </div>
    )
  }

  const defaultVisual =
    kind === 'section' && owner.icon ? (
      <span
        className="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
      >
        <i className={`bi ${owner.icon}`} />
      </span>
    ) : null
  const defaultDescription =
    kind === 'section' && owner.lead ? (
      <p className="text-body-secondary mb-0">{owner.lead}</p>
    ) : null

  return (
    <div className="min-w-0" data-id="hilos-page-head">
      {crumbs.length > 1 ? (
        <HilosBreadcrumb
          crumbs={crumbs}
          label={breadcrumbLabel}
          className="text-break"
        />
      ) : null}
      <div
        className={`d-flex align-items-start gap-3 ${rowShown ? 'mb-3' : 'mb-4'}`}
        data-id="hilos-page-head-block"
      >
        {visual === undefined ? defaultVisual : visual}
        <div className="d-flex flex-wrap align-items-start column-gap-3 row-gap-2 flex-grow-1 min-w-0">
          <div className="flex-grow-1 min-w-0">
            <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1
                id={headingId ?? localHeadingId}
                className="h4 mb-0 text-break"
                data-id="hilos-page-head-title"
              >
                {owner.label}
              </h1>
              {meta}
              {badges}
            </div>
            {description === undefined ? defaultDescription : description}
          </div>
          {action !== undefined && actionTo !== undefined ? (
            <HilosLink
              to={actionTo}
              className="btn btn-sm btn-outline-secondary text-nowrap"
              data-id="hilos-page-head-action"
            >
              {action.label}
            </HilosLink>
          ) : null}
        </div>
      </div>
      {rowShown ? (
        <nav
          aria-label={tabsLabel}
          className="mb-4 min-w-0"
          data-id="hilos-page-tabs"
        >
          <ul
            ref={row}
            className="nav nav-underline flex-nowrap overflow-x-auto border-bottom"
          >
            {tabs.map((tab) => (
              <li key={tab.page} className="nav-item flex-shrink-0">
                <HilosLink
                  to={tab.to}
                  className={
                    tab.page === page
                      ? 'nav-link text-nowrap active'
                      : 'nav-link text-nowrap'
                  }
                  aria-current={tab.page === page ? 'page' : undefined}
                  data-id={`hilos-page-tab-${tab.page}`}
                >
                  {tab.label}
                </HilosLink>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}
    </div>
  )
}

/**
 * Scroll the row so the current tab stands in its middle, within the row's
 * scroll limits; a row that is not drawn, detached, or without a current tab is
 * left alone. The jump is instant: nothing animates and nothing takes focus.
 *
 * @param container The row's list, or null when the row is not drawn.
 */
function centerCurrentTab(container: HTMLElement | null): void {
  if (container === null || !container.isConnected) {
    return
  }
  const current = container.querySelector<HTMLElement>('[aria-current="page"]')
  if (current === null) {
    return
  }
  const box = container.getBoundingClientRect()
  const tab = current.getBoundingClientRect()
  const target =
    container.scrollLeft +
    (tab.left - box.left) -
    (container.clientWidth - tab.width) / 2
  const limit = Math.max(0, container.scrollWidth - container.clientWidth)
  container.scrollLeft = Math.min(Math.max(target, 0), limit)
}
