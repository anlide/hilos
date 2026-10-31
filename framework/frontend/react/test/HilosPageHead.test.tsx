import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { RenderResult } from '@testing-library/react'
import type { Mock } from 'vitest'
import { ScopeManager, createSignal, pageIdentity } from '@hilos/core'
import type {
  HilosPageIdentity,
  HilosPathResolver,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosPageHead } from '../src/HilosPageHead.js'
import type { HilosPageHeadProps } from '../src/HilosPageHead.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Synthetic routes: no real owner is opted in by these tests. */
const ROUTES: Record<string, string> = {
  hilos: '/hilos',
  demo_section: '/hilos/demo',
  demo_section_list: '/hilos/demo/list',
  demo_section_gaps: '/hilos/demo/gaps',
  demo_section_gaps_machine: '/hilos/demo/gaps/machine',
  demo_entity: '/hilos/demo/list/{code}',
  demo_entity_names: '/hilos/demo/list/{code}/names',
  demo_activity: '/hilos/activity/{person}',
}

/** Resolve a synthetic key the way the router does: no address, no path. */
const resolvePath: HilosPathResolver = (page, params = {}) => {
  const route = ROUTES[page]
  if (route === undefined) {
    return undefined
  }
  let missing = false
  const path = route.replace(/\{(\w+)\}/g, (_token, name: string) => {
    const value = params[name]
    missing ||= value === undefined
    return value ?? ''
  })

  return missing ? undefined : path
}

/** A section whose row carries a child of a tab right after its parent. */
const SECTION: HilosPageIdentity = {
  label: 'Gaps',
  lead: 'What is still untranslated.',
  breadcrumb: [
    { page: 'hilos', label: 'Hilos' },
    { page: 'demo_section', label: 'Demo section' },
  ],
  children: [],
  pageHeadOwner: {
    page: 'demo_section',
    label: 'Demo section',
    lead: 'Languages, countries, and translation screens.',
    icon: 'bi-translate',
  },
  pageTabs: [
    { page: 'demo_section', label: 'Overview' },
    { page: 'demo_section_list', label: 'List' },
    { page: 'demo_section_gaps', label: 'Gaps' },
    { page: 'demo_section_gaps_machine', label: 'Machine' },
  ],
}

/** An entity opened from the section's list, with its live name laid in. */
const ENTITY: HilosPageIdentity = {
  label: 'Names',
  lead: 'Its names in every language.',
  breadcrumb: [
    { page: 'hilos', label: 'Hilos' },
    { page: 'demo_section', label: 'Demo section' },
    { page: 'demo_section_list', label: 'List' },
    { page: 'demo_entity', label: 'United States' },
  ],
  children: [],
  pageHeadOwner: {
    page: 'demo_entity',
    label: 'United States',
    lead: 'One entity of the list.',
    icon: 'bi-flag',
  },
  pageTabs: [
    { page: 'demo_entity', label: 'Overview' },
    { page: 'demo_entity_names', label: 'Names' },
  ],
}

const ENTITY_PARAMS = { code: 'US' }

/** The block content an entity page hands in, each piece findable by its probe. */
const PROBES = {
  visual: <span data-id="probe-tile">US</span>,
  meta: <span data-id="probe-code">us</span>,
  badges: (
    <span data-id="probe-badge" className="badge">
      Custom
    </span>
  ),
  description: <p data-id="probe-description">Currency $ USD</p>,
}

/** A mounted head and a way to hand it new props over the ones it started with. */
interface MountedHead extends RenderResult {
  update(next: Partial<HilosPageHeadProps>): void
}

/**
 * Mount a head over the section by default, optionally under a router or into
 * a container that is not in the document.
 *
 * @param props The props laid over the section defaults.
 * @param options The router to provide and the container to render into.
 */
function mountHead(
  props: Partial<HilosPageHeadProps> = {},
  options: { router?: HilosRouter; container?: HTMLElement } = {},
): MountedHead {
  let current = props
  const ui = () => {
    const head = (
      <HilosPageHead
        identity={SECTION}
        page="demo_section"
        params={{}}
        resolvePath={resolvePath}
        {...current}
      />
    )

    return options.router === undefined ? (
      head
    ) : (
      <HilosRouterContext.Provider value={options.router}>
        {head}
      </HilosRouterContext.Provider>
    )
  }
  const result = render(ui(), { container: options.container })

  return {
    ...result,
    update(next) {
      current = { ...current, ...next }
      result.rerender(ui())
    },
  }
}

/** A router whose own path match points at the given path, nothing more. */
function routerAt(path: string): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: true,
    }),
    currentPath: createSignal(path),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * @param head The mounted head.
 * @param selector The CSS selector to look for inside it.
 */
function find(head: RenderResult, selector: string): HTMLElement | null {
  return head.container.querySelector<HTMLElement>(selector)
}

/**
 * @param head The mounted head.
 * @param selector The CSS selector to look for inside it.
 */
function findAll(head: RenderResult, selector: string): HTMLElement[] {
  return [...head.container.querySelectorAll<HTMLElement>(selector)]
}

/**
 * @param head The mounted head.
 * @param page The page key of the tab.
 */
function tab(head: RenderResult, page: string): HTMLElement | null {
  return find(head, `[data-id="hilos-page-tab-${page}"]`)
}

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('HilosPageHead', () => {
  it('draws a section head: its icon, its title in the one h1, its lead', () => {
    const head = mountHead({ headingId: 'page-heading' })

    const block = find(head, '[data-id="hilos-page-head-block"]')
    const icon = block?.querySelector('i.bi.bi-translate')
    expect(icon).not.toBeNull()
    expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')

    const headings = findAll(head, 'h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].id).toBe('page-heading')
    expect(headings[0].dataset.id).toBe('hilos-page-head-title')
    // The owner, not the subscribing page: every tab shares one head.
    expect(headings[0].textContent).toBe('Demo section')
    expect(block?.textContent).toContain(
      'Languages, countries, and translation screens.',
    )
    expect(block?.textContent).not.toContain('What is still untranslated.')
  })

  it('gives the h1 an id of its own when the caller hands none', () => {
    const head = mountHead()

    const heading = find(head, 'h1')
    expect(heading?.id).toBeTruthy()
    expect(document.getElementById(heading?.id ?? '')).toBe(heading)
  })

  it('draws an entity block from the page content, without the section defaults', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: ENTITY_PARAMS,
      kind: 'entity',
      breadcrumbLabel: 'Path',
      tabsLabel: 'Entity pages',
      ...PROBES,
    })

    const block = find(head, '[data-id="hilos-page-head-block"]')
    for (const probe of ['tile', 'code', 'badge', 'description']) {
      expect(block?.querySelector(`[data-id="probe-${probe}"]`)).not.toBeNull()
    }
    expect(block?.querySelector('.bi-flag')).toBeNull()
    expect(block?.textContent).not.toContain('One entity of the list.')
    // The mark and the badges stand in the title's line, the description under it.
    const titleLine = find(head, 'h1')?.parentElement
    expect(titleLine?.querySelector('[data-id="probe-code"]')).not.toBeNull()
    expect(titleLine?.querySelector('[data-id="probe-badge"]')).not.toBeNull()
    expect(titleLine?.querySelector('[data-id="probe-description"]')).toBeNull()

    const headings = findAll(head, 'h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].textContent).toBe('United States')
    expect(
      find(head, '[data-id="hilos-breadcrumb"]')?.getAttribute('aria-label'),
    ).toBe('Path')
    expect(
      find(head, '[data-id="hilos-page-tabs"]')?.getAttribute('aria-label'),
    ).toBe('Entity pages')
  })

  it('draws an entity without content as its title alone', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: ENTITY_PARAMS,
      kind: 'entity',
    })

    const block = find(head, '[data-id="hilos-page-head-block"]')
    expect(block?.querySelector('i')).toBeNull()
    expect(block?.querySelector('p')).toBeNull()
    expect(block?.querySelector('h1')?.textContent).toBe('United States')
  })

  it('turns a section default off by an explicit null and replaces it by content', () => {
    const silenced = mountHead({ visual: null, description: null })
    const bare = find(silenced, '[data-id="hilos-page-head-block"]')
    expect(bare?.querySelector('i')).toBeNull()
    expect(bare?.querySelector('p')).toBeNull()
    expect(bare?.querySelector('h1')?.textContent).toBe('Demo section')
    cleanup()

    const replaced = mountHead({
      visual: PROBES.visual,
      description: PROBES.description,
    })
    const block = find(replaced, '[data-id="hilos-page-head-block"]')
    expect(block?.querySelector('.bi-translate')).toBeNull()
    expect(block?.querySelector('[data-id="probe-tile"]')).not.toBeNull()
    expect(block?.querySelector('[data-id="probe-description"]')).not.toBeNull()
    expect(block?.textContent).not.toContain(
      'Languages, countries, and translation screens.',
    )
  })

  it('lays the breadcrumb, the block and the row out in that order', () => {
    const head = mountHead()

    const order = findAll(head, '[data-id]').flatMap((node) => {
      const id = node.dataset.id
      return id === 'hilos-breadcrumb' ||
        id === 'hilos-page-head-block' ||
        id === 'hilos-page-tabs'
        ? [id]
        : []
    })
    expect(order).toEqual([
      'hilos-breadcrumb',
      'hilos-page-head-block',
      'hilos-page-tabs',
    ])
    expect(
      (head.container.firstElementChild as HTMLElement | null)?.dataset.id,
    ).toBe('hilos-page-head')
  })

  it('names the breadcrumb and links every crumb but the last', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity_names',
      params: ENTITY_PARAMS,
      kind: 'entity',
    })

    const crumbs = find(head, '[data-id="hilos-breadcrumb"]')
    expect(crumbs?.tagName).toBe('NAV')
    expect(crumbs?.getAttribute('aria-label')).toBe('Breadcrumb')
    expect(crumbs?.classList.contains('text-break')).toBe(true)
    expect(crumbs?.querySelector('ol')).not.toBeNull()
    expect(
      [...(crumbs?.querySelectorAll('a') ?? [])].map((link) =>
        link.getAttribute('href'),
      ),
    ).toEqual(['/hilos', '/hilos/demo', '/hilos/demo/list'])
    // The chain ends at the owner, not at the tab, and the last link is text.
    const current = crumbs?.querySelectorAll('[aria-current="page"]') ?? []
    expect(current).toHaveLength(1)
    expect(current[0].tagName).toBe('LI')
    expect(current[0].textContent).toBe('/United States')
  })

  it('keeps an intermediate crumb without an address as text', () => {
    const head = mountHead({
      identity: {
        ...SECTION,
        breadcrumb: [
          { page: 'hilos', label: 'Hilos' },
          { page: 'demo_unrouted', label: 'People' },
          { page: 'demo_section', label: 'Demo section' },
        ],
      },
    })

    const items = findAll(head, '.breadcrumb-item')
    expect(items.map((item) => item.textContent)).toEqual([
      'Hilos',
      '/People',
      '/Demo section',
    ])
    expect(items[1].querySelector('a')).toBeNull()
    expect(findAll(head, '[data-id="hilos-breadcrumb"] a')).toHaveLength(1)
  })

  it('draws the row as a named list of real links, no tab widget', () => {
    const head = mountHead({ page: 'demo_section_list' })

    const row = find(head, '[data-id="hilos-page-tabs"]')
    expect(row?.tagName).toBe('NAV')
    expect(row?.getAttribute('aria-label')).toBe('Page sections')
    expect(row?.querySelectorAll('ul > li > a')).toHaveLength(4)
    const links = [...(row?.querySelectorAll('a') ?? [])]
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/hilos/demo',
      '/hilos/demo/list',
      '/hilos/demo/gaps',
      '/hilos/demo/gaps/machine',
    ])
    expect(links.map((link) => link.textContent)).toEqual([
      'Overview',
      'List',
      'Gaps',
      'Machine',
    ])
    expect(find(head, '[role="tablist"]')).toBeNull()
    expect(find(head, '[role="tab"]')).toBeNull()
    expect(find(head, '.disabled')).toBeNull()
    expect(find(head, '.badge')).toBeNull()
  })

  it('marks the current tab by its page key, a child of a tab and not its parent', () => {
    const head = mountHead({ page: 'demo_section_gaps_machine' })

    const current = findAll(head, '[data-id="hilos-page-tabs"] [aria-current]')
    expect(current).toHaveLength(1)
    expect(current[0].dataset.id).toBe(
      'hilos-page-tab-demo_section_gaps_machine',
    )
    expect(current[0].getAttribute('aria-current')).toBe('page')
    expect(current[0].classList.contains('active')).toBe(true)
    // Its path extends its parent's, and its parent stays unmarked.
    expect(tab(head, 'demo_section_gaps')?.classList.contains('active')).toBe(
      false,
    )
    expect(findAll(head, '.nav-link.active')).toHaveLength(1)
  })

  it('marks no tab when the rendered page is not in the row', () => {
    const head = mountHead({ page: 'demo_elsewhere' })

    expect(find(head, '[data-id="hilos-page-tabs"]')).not.toBeNull()
    expect(findAll(head, '[data-id="hilos-page-tabs"] [aria-current]')).toEqual(
      [],
    )
    expect(find(head, '.nav-link.active')).toBeNull()
  })

  it("does not let the link's own path match mark another tab", () => {
    // The router already stands at the next tab's address while this screen
    // still shows the previous page: the head marks the page it was given.
    const head = mountHead(
      { page: 'demo_section' },
      { router: routerAt('/hilos/demo/list') },
    )

    expect(tab(head, 'demo_section_list')?.hasAttribute('aria-current')).toBe(
      false,
    )
    expect(tab(head, 'demo_section')?.getAttribute('aria-current')).toBe('page')
  })

  it('hides a one-link breadcrumb and a one-tab row but keeps the head', () => {
    const head = mountHead({
      identity: {
        ...SECTION,
        breadcrumb: [{ page: 'demo_section', label: 'Demo section' }],
        pageTabs: [{ page: 'demo_section', label: 'Overview' }],
      },
    })

    expect(find(head, '[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-tabs"]')).toBeNull()
    expect(find(head, 'h1')?.textContent).toBe('Demo section')
    expect(
      find(head, '[data-id="hilos-page-head-block"]')?.classList.contains(
        'mb-4',
      ),
    ).toBe(true)
  })

  it('counts the row after the route map drops the tabs it has no address for', () => {
    // Names needs the code the current route does not carry: one tab is left.
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: {},
      kind: 'entity',
    })

    expect(find(head, '[data-id="hilos-page-tabs"]')).toBeNull()
    expect(find(head, 'h1')?.textContent).toBe('United States')
  })

  it('opens the action in its own params, not laid over the current ones', () => {
    const resolve = vi.fn(resolvePath)
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: ENTITY_PARAMS,
      kind: 'entity',
      resolvePath: resolve,
      action: {
        page: 'demo_activity',
        label: 'Activity',
        params: { person: '12' },
      },
    })

    const button = find(head, '[data-id="hilos-page-head-action"]')
    expect(button?.tagName).toBe('A')
    expect(button?.getAttribute('href')).toBe('/hilos/activity/12')
    expect(button?.textContent).toBe('Activity')
    expect(button?.classList.contains('btn')).toBe(true)
    expect(resolve).toHaveBeenCalledWith('demo_activity', { person: '12' })
    expect(
      find(
        head,
        '[data-id="hilos-page-head-block"] [data-id="hilos-page-head-action"]',
      ),
    ).not.toBeNull()
  })

  it('opens the action in the current params when it carries none', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: ENTITY_PARAMS,
      kind: 'entity',
      action: { page: 'demo_entity_names', label: 'Names' },
    })

    expect(
      find(head, '[data-id="hilos-page-head-action"]')?.getAttribute('href'),
    ).toBe('/hilos/demo/list/US/names')
  })

  it('draws no button for an action without an address', () => {
    const missingPage = mountHead({
      action: { page: 'demo_missing', label: 'Nowhere' },
    })
    expect(find(missingPage, '[data-id="hilos-page-head-action"]')).toBeNull()
    expect(missingPage.container.textContent).not.toContain('Nowhere')

    // Explicit params are the whole context: the current ones fill no gap.
    const missingParam = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: { ...ENTITY_PARAMS, person: '12' },
      kind: 'entity',
      action: { page: 'demo_activity', label: 'Activity', params: {} },
    })
    expect(find(missingParam, '[data-id="hilos-page-head-action"]')).toBeNull()
    expect(missingParam.container.textContent).not.toContain('Activity')
  })

  it('shows only a silent skeleton until the answer arrives', () => {
    const head = mountHead({
      identity: undefined,
      kind: 'entity',
      action: { page: 'demo_section', label: 'Back' },
      ...PROBES,
    })

    const skeleton = find(head, '[data-id="hilos-page-head-skeleton"]')
    expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
    expect(skeleton?.querySelector('.rounded-3')).not.toBeNull()
    expect(find(head, 'h1')).toBeNull()
    expect(find(head, '[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-tabs"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-head-block"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-head-action"]')).toBeNull()
    expect(find(head, '[data-id^="probe-"]')).toBeNull()
    // The outlet announces the wait once; the head adds no second voice.
    expect(find(head, '[role="status"]')).toBeNull()
    expect(find(head, '[aria-live]')).toBeNull()
    expect(head.container.textContent).toBe('')
  })

  it('uses the optional skeleton picture only while the owner is unanswered', () => {
    const head = mountHead({
      identity: undefined,
      skeletonVisual: <span data-id="probe-skeleton-visual" />,
    })

    const skeleton = find(head, '[data-id="hilos-page-head-skeleton"]')
    expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
    expect(find(head, '[data-id="probe-skeleton-visual"]')).not.toBeNull()
    expect(find(head, 'h1')).toBeNull()
    expect(find(head, '[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-tabs"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-head-block"]')).toBeNull()

    head.update({ identity: SECTION })

    expect(find(head, '[data-id="probe-skeleton-visual"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-head-block"]')).not.toBeNull()
  })

  it("draws a section's skeleton tile round", () => {
    const head = mountHead({ identity: undefined })

    expect(
      find(head, '[data-id="hilos-page-head-skeleton"] .rounded-circle'),
    ).not.toBeNull()
  })

  it('stays on the skeleton for an identity without an owner', () => {
    const head = mountHead({
      identity: {
        label: SECTION.label,
        lead: SECTION.lead,
        breadcrumb: SECTION.breadcrumb,
        children: [],
      },
      ...PROBES,
    })

    expect(find(head, '[data-id="hilos-page-head-skeleton"]')).not.toBeNull()
    expect(find(head, 'h1')).toBeNull()
    expect(find(head, '[data-id^="probe-"]')).toBeNull()
  })

  it('stays on the skeleton for an answer whose owner is malformed', () => {
    // The core drops an owner without a lead, and the tabs beside it with it.
    const scopes = new ScopeManager()
    const scope = scopes.openPage('demo_section')
    scope.data.set('pageLabel', 'Gaps')
    scope.data.set('pageBreadcrumb', SECTION.breadcrumb)
    scope.data.set('pageHeadOwner', {
      page: 'demo_section',
      label: 'Demo section',
    })
    scope.data.set('pageTabs', SECTION.pageTabs)
    const answered = pageIdentity(scopes).get()
    expect(answered).toBeDefined()

    const head = mountHead({
      identity: answered,
      action: { page: 'demo_section', label: 'Back' },
      ...PROBES,
    })

    expect(find(head, '[data-id="hilos-page-head-skeleton"]')).not.toBeNull()
    expect(find(head, 'h1')).toBeNull()
    expect(find(head, '[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(find(head, '[data-id="hilos-page-tabs"]')).toBeNull()
    expect(find(head, '[data-id^="probe-"]')).toBeNull()
    expect(head.container.textContent).toBe('')
  })
})

describe('HilosPageHead centering the current tab', () => {
  /** Widths of the tabs, in pixels; a tab starts where the one before it ends. */
  const WIDTHS: Record<string, number> = {
    demo_section: 100,
    demo_section_list: 60,
    demo_section_gaps: 80,
    demo_section_gaps_machine: 120,
  }
  const ROW_LEFT = 20
  const ROW = '[data-id="hilos-page-tabs"] > ul'
  const TAB_PREFIX = 'hilos-page-tab-'

  /** The row's box: `width` visible pixels holding `content` pixels of tabs. */
  const box = { width: 200, content: 360 }

  /**
   * Give every row and its links a controlled layout before anything mounts:
   * the row stands `box.width` wide over `box.content` pixels of tabs, and a
   * tab's box is read off the links drawn at the moment it is asked for, moving
   * left as the row scrolls.
   */
  function layRows(): void {
    box.width = 200
    box.content = 360
    const widthOf = (link: HTMLElement) =>
      WIDTHS[(link.dataset.id ?? '').slice(TAB_PREFIX.length)] ?? 0
    vi.spyOn(Element.prototype, 'clientWidth', 'get').mockImplementation(
      function (this: Element) {
        return this.matches(ROW) ? box.width : 0
      },
    )
    vi.spyOn(Element.prototype, 'scrollWidth', 'get').mockImplementation(
      function (this: Element) {
        return this.matches(ROW) ? box.content : 0
      },
    )
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: Element) {
        if (this.matches(ROW)) {
          return { left: ROW_LEFT, width: box.width } as DOMRect
        }
        const row = this.closest(ROW)
        if (row === null || !(this instanceof HTMLElement)) {
          return { left: 0, width: 0 } as DOMRect
        }
        const links = [...row.querySelectorAll<HTMLElement>('a')]
        const offset = links
          .slice(0, links.indexOf(this))
          .reduce((sum, other) => sum + widthOf(other), 0)

        return {
          left: ROW_LEFT + offset - row.scrollLeft,
          width: widthOf(this),
        } as DOMRect
      },
    )
  }

  /** One stubbed ResizeObserver: its callback, its targets and its disconnect. */
  interface Observer {
    resize: () => void
    targets: Element[]
    disconnect: Mock
  }

  /** A ResizeObserver whose callback, targets and disconnects the test holds. */
  function observeResizes(): Observer[] {
    const observers: Observer[] = []
    vi.stubGlobal(
      'ResizeObserver',
      class implements Observer {
        resize: () => void
        targets: Element[] = []
        disconnect = vi.fn()
        constructor(callback: () => void) {
          this.resize = callback
          observers.push(this)
        }
        observe(target: Element) {
          this.targets.push(target)
        }
        unobserve() {}
      },
    )

    return observers
  }

  /** @param head The mounted head. */
  function rowOf(head: RenderResult): HTMLElement {
    const row = head.container.querySelector<HTMLElement>(ROW)
    if (row === null) {
      throw new Error('The head draws no row.')
    }

    return row
  }

  it('centers the current tab once mounted', () => {
    layRows()
    observeResizes()
    const head = mountHead({ page: 'demo_section_gaps' })

    // 160 + 0 - (200 - 80) / 2 = 100: the tab's middle at the row's middle.
    expect(rowOf(head).scrollLeft).toBe(100)
  })

  it('clamps the scroll to the row limits at both ends', () => {
    layRows()
    observeResizes()
    const first = mountHead({ page: 'demo_section_gaps' })
    const start = rowOf(first)
    expect(start.scrollLeft).toBe(100)
    // 100 + (0 - 100) - (200 - 100) / 2 = -50 lies before the row's start.
    first.update({ page: 'demo_section' })
    expect(start.scrollLeft).toBe(0)
    cleanup()

    const last = mountHead({ page: 'demo_section_gaps_machine' })
    // 240 - (200 - 120) / 2 = 200 lies past 360 - 200 = 160.
    expect(rowOf(last).scrollLeft).toBe(160)
  })

  it('re-centers on a new current key and on a resize, not on a rename', () => {
    layRows()
    const observers = observeResizes()
    const head = mountHead({ page: 'demo_section' })
    const row = rowOf(head)
    expect(row.scrollLeft).toBe(0)

    head.update({ page: 'demo_section_gaps' })
    expect(row.scrollLeft).toBe(100)

    // The reader scrolls by hand; a rename and a new caption keep the row's
    // keys, and with them the scroll.
    row.scrollLeft = 30
    head.update({
      identity: {
        ...SECTION,
        pageHeadOwner: { ...SECTION.pageHeadOwner!, label: 'Renamed' },
        pageTabs: SECTION.pageTabs!.map((entry) => ({
          ...entry,
          label: `${entry.label} (2)`,
        })),
      },
    })
    expect(head.container.querySelector('h1')?.textContent).toBe('Renamed')
    expect(row.scrollLeft).toBe(30)

    // A narrower box moves the middle: 160 - (120 - 80) / 2 = 140.
    box.width = 120
    expect(observers).toHaveLength(1)
    expect(observers[0].targets).toEqual([row])
    observers[0].resize()
    expect(row.scrollLeft).toBe(140)
  })

  it('re-centers when the row changes its tabs', () => {
    layRows()
    observeResizes()
    const head = mountHead({ page: 'demo_section_gaps' })
    const row = rowOf(head)
    expect(row.scrollLeft).toBe(100)

    // Without List, Gaps starts at 100: 100 - (200 - 80) / 2 = 40.
    head.update({
      identity: {
        ...SECTION,
        pageTabs: SECTION.pageTabs!.filter(
          (entry) => entry.page !== 'demo_section_list',
        ),
      },
    })
    expect(row.scrollLeft).toBe(40)
  })

  it('lets the observer go when the row disappears, and observes the row that comes back', () => {
    const observers = observeResizes()
    const head = mountHead({ page: 'demo_section' })
    expect(observers).toHaveLength(1)
    expect(observers[0].targets).toEqual([rowOf(head)])

    // The route map leaves one tab: the row is gone, and so is its observer.
    head.update({
      identity: {
        ...SECTION,
        pageTabs: [{ page: 'demo_section', label: 'Overview' }],
      },
    })
    expect(head.container.querySelector(ROW)).toBeNull()
    expect(observers[0].disconnect).toHaveBeenCalledTimes(1)

    head.update({ identity: SECTION })
    expect(observers).toHaveLength(2)
    expect(observers[1].targets).toEqual([rowOf(head)])
    expect(observers[1].disconnect).not.toHaveBeenCalled()
  })

  it('disconnects the observer when unmounted', () => {
    const observers = observeResizes()
    const head = mountHead({ page: 'demo_section' })
    expect(observers).toHaveLength(1)
    expect(observers[0].disconnect).not.toHaveBeenCalled()

    head.unmount()

    expect(observers[0].disconnect).toHaveBeenCalledTimes(1)
  })

  it('leaves a detached row, and a head without a row, alone', () => {
    layRows()
    const observers = observeResizes()
    const detached = mountHead(
      { page: 'demo_section_gaps' },
      { container: document.createElement('div') },
    )
    const row = rowOf(detached)
    expect(row.isConnected).toBe(false)
    expect(row.scrollLeft).toBe(0)
    observers[0].resize()
    expect(row.scrollLeft).toBe(0)
    detached.unmount()

    const single = mountHead({
      identity: {
        ...SECTION,
        pageTabs: [{ page: 'demo_section', label: 'Overview' }],
      },
    })
    expect(single.container.querySelector('ul')).toBeNull()
    expect(observers).toHaveLength(1)
  })
})
