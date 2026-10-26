import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createSignal } from '@hilos/core/base'
import type {
  HilosPageIdentity,
  HilosPathResolver,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'
import { h, nextTick, type VNode } from 'vue'

import HilosPageHead from './HilosPageHead.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

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

type HeadProps = InstanceType<typeof HilosPageHead>['$props']

/** Every head a test mounted and has not unmounted itself. */
const mounted = new Set<VueWrapper>()

function mountHead(
  props: Partial<HeadProps> = {},
  options: {
    slots?: Record<string, string | (() => VNode[])>
    router?: HilosRouter
    attach?: boolean
  } = {},
): VueWrapper {
  const wrapper = mount(HilosPageHead, {
    props: {
      identity: SECTION,
      page: 'demo_section',
      params: {},
      resolvePath,
      ...props,
    },
    slots: options.slots,
    attachTo: options.attach ? document.body : undefined,
    global: options.router
      ? { provide: { [hilosRouterKey as symbol]: options.router } }
      : undefined,
  })
  mounted.add(wrapper)

  return wrapper
}

/** Unmount a head before the test ends, so the afterEach does not repeat it. */
function unmountHead(head: VueWrapper): void {
  mounted.delete(head)
  head.unmount()
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

function tab(head: VueWrapper, page: string) {
  return head.find(`[data-id="hilos-page-tab-${page}"]`)
}

afterEach(() => {
  for (const head of mounted) {
    head.unmount()
  }
  mounted.clear()
  vi.unstubAllGlobals()
})

describe('HilosPageHead', () => {
  it('draws a section head: its icon, its title in the one h1, its lead', () => {
    const head = mountHead({ headingId: 'page-heading' })

    const block = head.find('[data-id="hilos-page-head-block"]')
    const icon = block.find('i.bi.bi-translate')
    expect(icon.exists()).toBe(true)
    expect(icon.element.parentElement?.getAttribute('aria-hidden')).toBe('true')

    const headings = head.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].attributes('id')).toBe('page-heading')
    expect(headings[0].attributes('data-id')).toBe('hilos-page-head-title')
    // The owner, not the subscribing page: every tab shares one head.
    expect(headings[0].text()).toBe('Demo section')
    expect(block.text()).toContain(
      'Languages, countries, and translation screens.',
    )
    expect(block.text()).not.toContain('What is still untranslated.')
  })

  it('gives the h1 an id of its own when the caller hands none', () => {
    const head = mountHead()

    expect(head.get('h1').attributes('id')).toBeTruthy()
  })

  it('draws an entity block from the page slots, without the section defaults', () => {
    const head = mountHead(
      {
        identity: ENTITY,
        page: 'demo_entity',
        params: ENTITY_PARAMS,
        kind: 'entity',
        breadcrumbLabel: 'Path',
        tabsLabel: 'Entity pages',
      },
      {
        slots: {
          visual: '<span data-id="probe-tile">US</span>',
          meta: '<span data-id="probe-code">us</span>',
          badges: '<span data-id="probe-badge" class="badge">Custom</span>',
          description: '<p data-id="probe-description">Currency $ USD</p>',
        },
      },
    )

    const block = head.get('[data-id="hilos-page-head-block"]')
    for (const probe of ['tile', 'code', 'badge', 'description']) {
      expect(block.find(`[data-id="probe-${probe}"]`).exists()).toBe(true)
    }
    expect(block.find('.bi-flag').exists()).toBe(false)
    expect(block.text()).not.toContain('One entity of the list.')

    const headings = head.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].text()).toBe('United States')
    expect(
      head.get('[data-id="hilos-breadcrumb"]').attributes('aria-label'),
    ).toBe('Path')
    expect(
      head.get('[data-id="hilos-page-tabs"]').attributes('aria-label'),
    ).toBe('Entity pages')
  })

  it('draws an entity without slots as its title alone', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: ENTITY_PARAMS,
      kind: 'entity',
    })

    const block = head.get('[data-id="hilos-page-head-block"]')
    expect(block.find('i').exists()).toBe(false)
    expect(block.find('p').exists()).toBe(false)
    expect(block.get('h1').text()).toBe('United States')
  })

  it('lays the breadcrumb, the block and the row out in that order', () => {
    const head = mountHead()

    const order = [
      ...(head.element as HTMLElement).querySelectorAll<HTMLElement>(
        '[data-id]',
      ),
    ].flatMap((node) => {
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
    expect(head.attributes('data-id')).toBe('hilos-page-head')
  })

  it('names the breadcrumb and links every crumb but the last', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity_names',
      params: ENTITY_PARAMS,
      kind: 'entity',
    })

    const crumbs = head.get('[data-id="hilos-breadcrumb"]')
    expect(crumbs.element.tagName).toBe('NAV')
    expect(crumbs.attributes('aria-label')).toBe('Breadcrumb')
    expect(crumbs.find('ol').exists()).toBe(true)
    expect(crumbs.findAll('a').map((link) => link.attributes('href'))).toEqual([
      '/hilos',
      '/hilos/demo',
      '/hilos/demo/list',
    ])
    // The chain ends at the owner, not at the tab, and the last link is text.
    const current = crumbs.findAll('[aria-current="page"]')
    expect(current).toHaveLength(1)
    expect(current[0].element.tagName).toBe('LI')
    expect(current[0].text()).toBe('United States')
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

    const items = head.findAll('.breadcrumb-item')
    expect(items.map((item) => item.text())).toEqual([
      'Hilos',
      'People',
      'Demo section',
    ])
    expect(items[1].find('a').exists()).toBe(false)
    expect(head.findAll('[data-id="hilos-breadcrumb"] a')).toHaveLength(1)
  })

  it('draws the row as a named list of real links, no tab widget', () => {
    const head = mountHead({ page: 'demo_section_list' })

    const row = head.get('[data-id="hilos-page-tabs"]')
    expect(row.element.tagName).toBe('NAV')
    expect(row.attributes('aria-label')).toBe('Page sections')
    expect(row.findAll('ul > li > a')).toHaveLength(4)
    expect(row.findAll('a').map((link) => link.attributes('href'))).toEqual([
      '/hilos/demo',
      '/hilos/demo/list',
      '/hilos/demo/gaps',
      '/hilos/demo/gaps/machine',
    ])
    expect(row.findAll('a').map((link) => link.text())).toEqual([
      'Overview',
      'List',
      'Gaps',
      'Machine',
    ])
    expect(head.find('[role="tablist"]').exists()).toBe(false)
    expect(head.find('[role="tab"]').exists()).toBe(false)
    expect(head.find('.disabled').exists()).toBe(false)
    expect(head.find('.badge').exists()).toBe(false)
  })

  it('marks the current tab by its page key, a child of a tab and not its parent', () => {
    const head = mountHead({ page: 'demo_section_gaps_machine' })

    const current = head.findAll('[data-id="hilos-page-tabs"] [aria-current]')
    expect(current).toHaveLength(1)
    expect(current[0].attributes('data-id')).toBe(
      'hilos-page-tab-demo_section_gaps_machine',
    )
    expect(current[0].attributes('aria-current')).toBe('page')
    expect(current[0].classes()).toContain('active')
    // Its path extends its parent's, and its parent stays unmarked.
    expect(tab(head, 'demo_section_gaps').classes()).not.toContain('active')
    expect(head.findAll('.nav-link.active')).toHaveLength(1)
  })

  it('marks no tab when the rendered page is not in the row', () => {
    const head = mountHead({ page: 'demo_elsewhere' })

    expect(head.find('[data-id="hilos-page-tabs"]').exists()).toBe(true)
    expect(head.findAll('[data-id="hilos-page-tabs"] [aria-current]')).toEqual(
      [],
    )
    expect(head.find('.nav-link.active').exists()).toBe(false)
  })

  it("does not let the link's own path match mark another tab", () => {
    // The router already stands at the next tab's address while this screen
    // still shows the previous page: the head marks the page it was given.
    const head = mountHead(
      { page: 'demo_section' },
      { router: routerAt('/hilos/demo/list') },
    )

    expect(tab(head, 'demo_section_list').attributes('aria-current')).toBe(
      undefined,
    )
    expect(tab(head, 'demo_section').attributes('aria-current')).toBe('page')
  })

  it('hides a one-link breadcrumb and a one-tab row but keeps the head', () => {
    const head = mountHead({
      identity: {
        ...SECTION,
        breadcrumb: [{ page: 'demo_section', label: 'Demo section' }],
        pageTabs: [{ page: 'demo_section', label: 'Overview' }],
      },
    })

    expect(head.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(head.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(head.get('h1').text()).toBe('Demo section')
    expect(head.get('[data-id="hilos-page-head-block"]').classes()).toContain(
      'mb-4',
    )
  })

  it('counts the row after the route map drops the tabs it has no address for', () => {
    // Names needs the code the current route does not carry: one tab is left.
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: {},
      kind: 'entity',
    })

    expect(head.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(head.get('h1').text()).toBe('United States')
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

    const button = head.get('[data-id="hilos-page-head-action"]')
    expect(button.element.tagName).toBe('A')
    expect(button.attributes('href')).toBe('/hilos/activity/12')
    expect(button.text()).toBe('Activity')
    expect(button.classes()).toContain('btn')
    expect(resolve).toHaveBeenCalledWith('demo_activity', { person: '12' })
    expect(
      head
        .get('[data-id="hilos-page-head-block"]')
        .find('[data-id="hilos-page-head-action"]')
        .exists(),
    ).toBe(true)
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
      head.get('[data-id="hilos-page-head-action"]').attributes('href'),
    ).toBe('/hilos/demo/list/US/names')
  })

  it('draws no button for an action without an address', () => {
    const missingPage = mountHead({
      action: { page: 'demo_missing', label: 'Nowhere' },
    })
    expect(
      missingPage.find('[data-id="hilos-page-head-action"]').exists(),
    ).toBe(false)
    expect(missingPage.text()).not.toContain('Nowhere')

    const missingParam = mountHead({
      action: { page: 'demo_activity', label: 'Activity', params: {} },
    })
    expect(
      missingParam.find('[data-id="hilos-page-head-action"]').exists(),
    ).toBe(false)
  })

  it('shows only a silent skeleton until the answer arrives', async () => {
    const skeletonVisual = vi.fn(() => [
      h('span', { 'data-id': 'probe-skeleton-visual' }),
    ])
    const visual = vi.fn(() => [h('span', { 'data-id': 'probe-real-visual' })])
    const head = mountHead(
      {
        identity: undefined,
        kind: 'entity',
        action: { page: 'demo_section', label: 'Back' },
      },
      {
        slots: {
          'skeleton-visual': skeletonVisual,
          visual,
          meta: '<span data-id="probe-code">us</span>',
          badges: '<span data-id="probe-badge">Custom</span>',
          description: '<p data-id="probe-description">Currency</p>',
        },
      },
    )

    const skeleton = head.get('[data-id="hilos-page-head-skeleton"]')
    expect(skeleton.attributes('aria-hidden')).toBe('true')
    expect(skeleton.findAll('.placeholder')).toHaveLength(2)
    expect(skeleton.find('[data-id="probe-skeleton-visual"]').exists()).toBe(
      true,
    )
    expect(head.find('h1').exists()).toBe(false)
    expect(head.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(head.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(head.find('[data-id="hilos-page-head-block"]').exists()).toBe(false)
    expect(head.find('[data-id="hilos-page-head-action"]').exists()).toBe(false)
    expect(head.find('[data-id="probe-real-visual"]').exists()).toBe(false)
    expect(head.find('[data-id="probe-code"]').exists()).toBe(false)
    expect(head.find('[data-id="probe-badge"]').exists()).toBe(false)
    expect(head.find('[data-id="probe-description"]').exists()).toBe(false)
    expect(skeletonVisual).toHaveBeenCalledOnce()
    expect(visual).not.toHaveBeenCalled()
    // The outlet announces the wait once; the head adds no second voice.
    expect(head.find('[role="status"]').exists()).toBe(false)
    expect(head.find('[aria-live]').exists()).toBe(false)
    expect(head.text()).toBe('')

    await head.setProps({ identity: SECTION })
    expect(head.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      false,
    )
    expect(head.find('[data-id="probe-skeleton-visual"]').exists()).toBe(false)
    expect(head.find('[data-id="probe-real-visual"]').exists()).toBe(true)
    expect(skeletonVisual).toHaveBeenCalledOnce()
    expect(visual).toHaveBeenCalledOnce()
  })

  it("draws a section's skeleton tile round", () => {
    const head = mountHead({ identity: undefined })

    expect(
      head
        .get('[data-id="hilos-page-head-skeleton"]')
        .find('.rounded-circle')
        .exists(),
    ).toBe(true)
  })

  it("draws an entity's skeleton tile square", () => {
    const head = mountHead({ identity: undefined, kind: 'entity' })
    const skeleton = head.get('[data-id="hilos-page-head-skeleton"]')

    expect(skeleton.find('.rounded-3').exists()).toBe(true)
    expect(skeleton.find('.rounded-circle').exists()).toBe(false)
  })

  it('stays on the skeleton for an identity without an owner', () => {
    const head = mountHead({
      identity: {
        label: SECTION.label,
        lead: SECTION.lead,
        breadcrumb: SECTION.breadcrumb,
        children: [],
      },
    })

    expect(head.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(head.find('h1').exists()).toBe(false)
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
  const TAB_PREFIX = 'hilos-page-tab-'

  /**
   * Give the row and its links a controlled layout: a box `width` wide holding
   * `content` pixels of tabs. A tab's box is read off the links drawn at the
   * moment it is asked for, and moves left as the row scrolls.
   *
   * @param head The mounted head.
   * @param width The row's visible width.
   * @param content The row's scrolled content width.
   */
  function lay(head: VueWrapper, width: number, content: number): HTMLElement {
    const row = head.get('[data-id="hilos-page-tabs"] ul')
      .element as HTMLElement
    Object.defineProperty(row, 'clientWidth', {
      configurable: true,
      get: () => width,
    })
    Object.defineProperty(row, 'scrollWidth', {
      configurable: true,
      get: () => content,
    })
    row.getBoundingClientRect = () => ({ left: ROW_LEFT, width }) as DOMRect
    const links = () => [...row.querySelectorAll<HTMLElement>('a')]
    const widthOf = (link: HTMLElement) =>
      WIDTHS[(link.dataset.id ?? '').slice(TAB_PREFIX.length)]
    for (const link of links()) {
      link.getBoundingClientRect = () => {
        const before = links().slice(0, links().indexOf(link))
        const offset = before.reduce((sum, other) => sum + widthOf(other), 0)

        return {
          left: ROW_LEFT + offset - row.scrollLeft,
          width: widthOf(link),
        } as DOMRect
      }
    }

    return row
  }

  /** A ResizeObserver whose callback and disconnects the test holds. */
  function observeResizes() {
    const observers: { resize: () => void; disconnect: () => void }[] = []
    const disconnect = vi.fn()
    vi.stubGlobal(
      'ResizeObserver',
      class {
        resize: () => void
        constructor(callback: () => void) {
          this.resize = callback
          observers.push(this)
        }
        observe() {}
        unobserve() {}
        disconnect = disconnect
      },
    )

    return { observers, disconnect }
  }

  it('centers the current tab once mounted', async () => {
    observeResizes()
    const head = mountHead({ page: 'demo_section_gaps' }, { attach: true })
    const row = lay(head, 200, 360)

    await nextTick()

    // 160 + 0 - (200 - 80) / 2 = 100: the tab's middle at the row's middle.
    expect(row.scrollLeft).toBe(100)
  })

  it('clamps the scroll to the row limits at both ends', async () => {
    observeResizes()
    const first = mountHead({ page: 'demo_section' }, { attach: true })
    const start = lay(first, 200, 360)
    start.scrollLeft = 40
    await nextTick()
    expect(start.scrollLeft).toBe(0)
    unmountHead(first)

    const last = mountHead(
      { page: 'demo_section_gaps_machine' },
      { attach: true },
    )
    const end = lay(last, 200, 360)
    await nextTick()
    // 240 - (200 - 120) / 2 = 200 lies past 360 - 200 = 160.
    expect(end.scrollLeft).toBe(160)
  })

  it('re-centers on a new current key and on a resize, not on a rename', async () => {
    const { observers } = observeResizes()
    const head = mountHead({ page: 'demo_section' }, { attach: true })
    const row = lay(head, 200, 360)
    await nextTick()
    expect(row.scrollLeft).toBe(0)

    await head.setProps({ page: 'demo_section_gaps' })
    await nextTick()
    expect(row.scrollLeft).toBe(100)

    // The reader scrolls by hand; a rename keeps the row and its scroll.
    row.scrollLeft = 30
    await head.setProps({
      identity: {
        ...SECTION,
        pageHeadOwner: { ...SECTION.pageHeadOwner!, label: 'Renamed' },
      },
    })
    await nextTick()
    expect(head.get('h1').text()).toBe('Renamed')
    expect(row.scrollLeft).toBe(30)

    // A narrower box moves the middle: 160 - (120 - 80) / 2 = 140.
    Object.defineProperty(row, 'clientWidth', {
      configurable: true,
      get: () => 120,
    })
    expect(observers).toHaveLength(1)
    observers[0].resize()
    expect(row.scrollLeft).toBe(140)
  })

  it('re-centers when the row changes its tabs', async () => {
    observeResizes()
    const head = mountHead({ page: 'demo_section_gaps' }, { attach: true })
    const row = lay(head, 200, 360)
    await nextTick()
    expect(row.scrollLeft).toBe(100)

    // Without List, Gaps starts at 100: 100 - (200 - 80) / 2 = 40.
    await head.setProps({
      identity: {
        ...SECTION,
        pageTabs: SECTION.pageTabs!.filter(
          (entry) => entry.page !== 'demo_section_list',
        ),
      },
    })
    await nextTick()
    expect(row.scrollLeft).toBe(40)
  })

  it('disconnects the observer when unmounted', async () => {
    const { observers, disconnect } = observeResizes()
    const head = mountHead({ page: 'demo_section' }, { attach: true })
    await nextTick()
    expect(observers).toHaveLength(1)
    expect(disconnect).not.toHaveBeenCalled()

    unmountHead(head)

    expect(disconnect).toHaveBeenCalledTimes(1)
  })

  it('leaves a detached row, and a head without a row, alone', async () => {
    const { observers } = observeResizes()
    const detached = mountHead({ page: 'demo_section_gaps' })
    const row = lay(detached, 200, 360)
    await nextTick()
    observers[0].resize()
    expect(row.scrollLeft).toBe(0)
    unmountHead(detached)

    const single = mountHead(
      {
        identity: {
          ...SECTION,
          pageTabs: [{ page: 'demo_section', label: 'Overview' }],
        },
      },
      { attach: true },
    )
    await nextTick()
    expect(single.find('ul').exists()).toBe(false)
    expect(observers).toHaveLength(1)
  })
})
