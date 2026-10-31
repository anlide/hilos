// The Angular peer of vue/src/HilosPageHead.test.ts: the shared head standing
// alone — a section head and an entity block with the page's templates and the
// one h1, crumbs and tabs resolved through the route map, the current tab by the
// rendered page key whatever path the router stands at, the one transition, the
// silent skeleton, and the row centering its current tab with controlled
// metrics (HIL-1605).
import { Component, Directive, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { createSignal } from '@hilos/core'
import type {
  HilosPageHeadAction,
  HilosPageIdentity,
  HilosPathResolver,
  HilosRouter,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Mock } from 'vitest'

import { HilosPageHead } from '../src/HilosPageHead.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

/**
 * Resolve a synthetic key the way the router does: no address, no path.
 *
 * @param page The page key to resolve.
 * @param params The route params its placeholders are filled from.
 * @returns The path, or undefined for an unrouted key or a missing param.
 */
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

/** The row's scrolling list, as the head draws it. */
const ROW = '[data-id="hilos-page-tabs"] ul'

/** Every input of the head a case may set; the rest keep their defaults. */
interface HeadInputs {
  identity: HilosPageIdentity | undefined
  page: string
  params: Record<string, string>
  resolvePath: HilosPathResolver
  headingId: string
  kind: 'section' | 'entity'
  breadcrumbLabel: string
  tabsLabel: string
  action: HilosPageHeadAction
}

/**
 * Mount the head on its own, over the section by default.
 *
 * @param inputs The inputs to set; an input left out keeps its default.
 * @param router The router HilosLink reads, or undefined for none.
 * @returns The rendered fixture.
 */
function mountHead(
  inputs: Partial<HeadInputs> = {},
  router?: HilosRouter,
): ComponentFixture<HilosPageHead> {
  if (router !== undefined) {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router }],
    })
  }
  const fixture = TestBed.createComponent(HilosPageHead)
  const given: Partial<HeadInputs> = {
    identity: SECTION,
    page: 'demo_section',
    params: {},
    resolvePath,
    ...inputs,
  }
  for (const [name, value] of Object.entries(given)) {
    fixture.componentRef.setInput(name, value)
  }
  fixture.detectChanges()

  return fixture
}

/**
 * Set inputs on a mounted head and render the change.
 *
 * @param fixture The mounted head.
 * @param inputs The inputs to change.
 */
function update(
  fixture: ComponentFixture<HilosPageHead>,
  inputs: Partial<HeadInputs>,
): void {
  for (const [name, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(name, value)
  }
  fixture.detectChanges()
}

/**
 * The rendered root of a fixture.
 *
 * @param fixture The mounted head or its host.
 * @returns Its host element.
 */
function root(fixture: ComponentFixture<unknown>): HTMLElement {
  return fixture.nativeElement as HTMLElement
}

/**
 * The one element a selector names.
 *
 * @param fixture The mounted head or its host.
 * @param selector What to look for.
 * @returns The element; the case fails when it is not drawn.
 */
function get(
  fixture: ComponentFixture<unknown>,
  selector: string,
): HTMLElement {
  const element = root(fixture).querySelector<HTMLElement>(selector)
  if (element === null) {
    throw new Error(`Nothing matches ${selector}`)
  }

  return element
}

/**
 * Whether anything a selector names is drawn.
 *
 * @param fixture The mounted head or its host.
 * @param selector What to look for.
 * @returns True when at least one element matches.
 */
function has(fixture: ComponentFixture<unknown>, selector: string): boolean {
  return root(fixture).querySelector(selector) !== null
}

/**
 * The anchor of one tab.
 *
 * @param fixture The mounted head.
 * @param page The tab's page key.
 * @returns The tab's link.
 */
function tab(fixture: ComponentFixture<unknown>, page: string): HTMLElement {
  return get(fixture, `[data-id="hilos-page-tab-${page}"]`)
}

/**
 * A router whose own path match points at the given path, nothing more.
 *
 * @param path The path the router stands at.
 * @returns The router HilosLink reads.
 */
function routerAt(path: string): HilosRouter {
  return {
    currentPath: createSignal(path),
    navigate: () => {},
  } as unknown as HilosRouter
}

/** How many probe elements the page's templates have created so far. */
let probesCreated = 0

/** Counts every probe element a page template creates. */
@Directive({ selector: '[testProbe]' })
class TestProbe {
  constructor() {
    probesCreated++
  }
}

/** A page module handing the head the four block templates. */
@Component({
  selector: 'test-page-head-host',
  imports: [HilosPageHead, TestProbe],
  template: `
    <hilos-page-head
      [identity]="identity()"
      [page]="page()"
      [params]="params()"
      [resolvePath]="resolvePath"
      [kind]="kind()"
      [action]="action()"
      [visual]="visual"
      [meta]="meta"
      [badges]="badges"
      [description]="description"
    />
    <ng-template #visual
      ><span testProbe data-id="probe-tile">US</span></ng-template
    >
    <ng-template #meta
      ><span testProbe data-id="probe-code">us</span></ng-template
    >
    <ng-template #badges
      ><span testProbe class="badge" data-id="probe-badge"
        >Custom</span
      ></ng-template
    >
    <ng-template #description
      ><p testProbe data-id="probe-description">Currency $ USD</p></ng-template
    >
  `,
})
class PageHeadHost {
  readonly identity = signal<HilosPageIdentity | undefined>(ENTITY)
  readonly page = signal('demo_entity')
  readonly params = signal<Record<string, string>>(ENTITY_PARAMS)
  readonly kind = signal<'section' | 'entity'>('entity')
  readonly action = signal<HilosPageHeadAction | undefined>(undefined)
  protected readonly resolvePath = resolvePath
}

/** A page module with a loading picture and real block templates. */
@Component({
  selector: 'test-page-head-skeleton-host',
  imports: [HilosPageHead],
  template: `
    <ng-template #skeletonVisual>
      <span data-id="skeleton-visual"></span>
    </ng-template>
    <ng-template #visual>
      <span data-id="live-visual"></span>
    </ng-template>
    <ng-template #description>
      <p data-id="live-description">Owner description</p>
    </ng-template>
    <hilos-page-head
      [identity]="identity()"
      page="demo_entity"
      [params]="params"
      [resolvePath]="resolvePath"
      kind="entity"
      [skeletonVisual]="skeletonVisual"
      [visual]="visual"
      [description]="description"
    />
  `,
})
class PageHeadSkeletonHost {
  readonly identity = signal<HilosPageIdentity | undefined>(undefined)
  protected readonly params: Record<string, string> = ENTITY_PARAMS
  protected readonly resolvePath = resolvePath
}

/**
 * Mount the head inside a page module that hands it every block template.
 *
 * @param set Adjusts the host's bindings before the first render.
 * @returns The rendered host.
 */
function mountWithTemplates(
  set: (host: PageHeadHost) => void = () => {},
): ComponentFixture<PageHeadHost> {
  probesCreated = 0
  const fixture = TestBed.createComponent(PageHeadHost)
  set(fixture.componentInstance)
  fixture.detectChanges()

  return fixture
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('HilosPageHead', () => {
  it('draws a section head: its icon, its title in the one h1, its lead', () => {
    const head = mountHead({ headingId: 'page-heading' })

    const block = get(head, '[data-id="hilos-page-head-block"]')
    const icon = block.querySelector('i.bi.bi-translate')
    expect(icon).not.toBeNull()
    expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
    expect(icon?.parentElement?.classList).toContain('rounded-circle')

    const headings = root(head).querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]?.id).toBe('page-heading')
    expect(headings[0]?.dataset['id']).toBe('hilos-page-head-title')
    // The owner, not the subscribing page: every tab shares one head.
    expect(headings[0]?.textContent?.trim()).toBe('Demo section')
    expect(headings[0]?.classList).toContain('text-break')
    expect(block.textContent).toContain(
      'Languages, countries, and translation screens.',
    )
    expect(block.textContent).not.toContain('What is still untranslated.')
  })

  it('gives the h1 an id of its own when the caller hands none', () => {
    const first = get(mountHead(), 'h1').id
    const second = get(mountHead(), 'h1').id

    expect(first).toBeTruthy()
    expect(second).toBeTruthy()
    expect(second).not.toBe(first)
  })

  it('draws an entity block from the page templates, without the section defaults', () => {
    const host = mountWithTemplates()

    const block = get(host, '[data-id="hilos-page-head-block"]')
    for (const probe of ['tile', 'code', 'badge', 'description']) {
      expect(block.querySelector(`[data-id="probe-${probe}"]`)).not.toBeNull()
    }
    expect(block.querySelector('.bi-flag')).toBeNull()
    expect(block.textContent).not.toContain('One entity of the list.')

    const headings = root(host).querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]?.textContent?.trim()).toBe('United States')
    // The meta and the badges stand beside the title, in the title's line.
    const titleLine = get(host, 'h1').parentElement
    expect(titleLine?.querySelector('[data-id="probe-code"]')).not.toBeNull()
    expect(titleLine?.querySelector('[data-id="probe-badge"]')).not.toBeNull()
  })

  it("lets a section's templates replace its icon and its lead", () => {
    const host = mountWithTemplates((page) => {
      page.identity.set(SECTION)
      page.page.set('demo_section')
      page.params.set({})
      page.kind.set('section')
    })

    const block = get(host, '[data-id="hilos-page-head-block"]')
    expect(block.querySelector('[data-id="probe-tile"]')).not.toBeNull()
    expect(block.querySelector('[data-id="probe-description"]')).not.toBeNull()
    expect(block.querySelector('.bi-translate')).toBeNull()
    expect(block.textContent).not.toContain(
      'Languages, countries, and translation screens.',
    )
  })

  it('draws an entity without templates as its title alone', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: ENTITY_PARAMS,
      kind: 'entity',
    })

    const block = get(head, '[data-id="hilos-page-head-block"]')
    expect(block.querySelector('i')).toBeNull()
    expect(block.querySelector('p')).toBeNull()
    expect(block.querySelector('h1')?.textContent?.trim()).toBe('United States')
  })

  it('lays the breadcrumb, the block and the row out in that order', () => {
    const head = mountHead()

    const order = [
      ...root(head).querySelectorAll<HTMLElement>('[data-id]'),
    ].flatMap((node) => {
      const id = node.dataset['id']
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
    const outer = get(head, '[data-id="hilos-page-head"]')
    expect(outer.classList).toContain('min-w-0')
    expect(get(head, '[data-id="hilos-page-head-block"]').classList).toContain(
      'mb-3',
    )
  })

  it('names the breadcrumb and links every crumb but the last', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity_names',
      params: ENTITY_PARAMS,
      kind: 'entity',
    })

    const crumbs = get(head, '[data-id="hilos-breadcrumb"]')
    expect(crumbs.tagName).toBe('NAV')
    expect(crumbs.getAttribute('aria-label')).toBe('Breadcrumb')
    expect(crumbs.querySelector('ol')).not.toBeNull()
    expect(
      [...crumbs.querySelectorAll('a')].map((link) =>
        link.getAttribute('href'),
      ),
    ).toEqual(['/hilos', '/hilos/demo', '/hilos/demo/list'])
    // The chain ends at the owner, not at the tab, and the last link is text.
    const current = crumbs.querySelectorAll('[aria-current]')
    expect(current).toHaveLength(1)
    expect(current[0]?.tagName).toBe('LI')
    expect(current[0]?.getAttribute('aria-current')).toBe('page')
    expect(current[0]?.textContent).toContain('United States')
    expect(current[0]?.querySelector('a')).toBeNull()
  })

  it('names the breadcrumb and the row by the labels it is given', () => {
    const head = mountHead({
      identity: ENTITY,
      page: 'demo_entity',
      params: ENTITY_PARAMS,
      kind: 'entity',
      breadcrumbLabel: 'Path',
      tabsLabel: 'Entity pages',
    })

    expect(
      get(head, '[data-id="hilos-breadcrumb"]').getAttribute('aria-label'),
    ).toBe('Path')
    expect(
      get(head, '[data-id="hilos-page-tabs"]').getAttribute('aria-label'),
    ).toBe('Entity pages')
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

    const items = [
      ...root(head).querySelectorAll('[data-id="hilos-breadcrumb"] li'),
    ]
    expect(
      items.map((item) => item.textContent?.replace('/', '').trim()),
    ).toEqual(['Hilos', 'People', 'Demo section'])
    expect(items[1]?.querySelector('a')).toBeNull()
    expect(
      root(head).querySelectorAll('[data-id="hilos-breadcrumb"] a'),
    ).toHaveLength(1)
  })

  it('draws the row as a named list of real links, no tab widget', () => {
    const head = mountHead({ page: 'demo_section_list' })

    const row = get(head, '[data-id="hilos-page-tabs"]')
    expect(row.tagName).toBe('NAV')
    expect(row.getAttribute('aria-label')).toBe('Page sections')
    expect(row.classList).toContain('min-w-0')
    expect(row.querySelectorAll('ul > li > a')).toHaveLength(4)
    expect(
      [...row.querySelectorAll('a')].map((link) => link.getAttribute('href')),
    ).toEqual([
      '/hilos/demo',
      '/hilos/demo/list',
      '/hilos/demo/gaps',
      '/hilos/demo/gaps/machine',
    ])
    expect(
      [...row.querySelectorAll('a')].map((link) => link.textContent?.trim()),
    ).toEqual(['Overview', 'List', 'Gaps', 'Machine'])
    expect(has(head, '[role="tablist"]')).toBe(false)
    expect(has(head, '[role="tab"]')).toBe(false)
    expect(has(head, '.disabled')).toBe(false)
    expect(has(head, '.badge')).toBe(false)
  })

  it('scrolls the row sideways at any width, its tabs kept on one line', () => {
    const head = mountHead()

    const list = get(head, ROW)
    for (const name of [
      'nav',
      'nav-underline',
      'flex-nowrap',
      'overflow-x-auto',
      'border-bottom',
    ]) {
      expect(list.classList).toContain(name)
    }
    for (const item of list.querySelectorAll('li')) {
      expect(item.classList).toContain('nav-item')
      expect(item.classList).toContain('flex-shrink-0')
    }
    for (const link of list.querySelectorAll('a')) {
      expect(link.classList).toContain('nav-link')
      expect(link.classList).toContain('text-nowrap')
    }
  })

  it('marks the current tab by its page key, a child of a tab and not its parent', () => {
    const head = mountHead({ page: 'demo_section_gaps_machine' })

    const current = root(head).querySelectorAll<HTMLElement>(
      '[data-id="hilos-page-tabs"] [aria-current]',
    )
    expect(current).toHaveLength(1)
    expect(current[0]?.dataset['id']).toBe(
      'hilos-page-tab-demo_section_gaps_machine',
    )
    expect(current[0]?.getAttribute('aria-current')).toBe('page')
    expect(current[0]?.classList).toContain('active')
    // Its path extends its parent's, and its parent stays unmarked.
    expect(tab(head, 'demo_section_gaps').classList).not.toContain('active')
    expect(root(head).querySelectorAll('.nav-link.active')).toHaveLength(1)
  })

  it('marks no tab when the rendered page is not in the row', () => {
    const head = mountHead({ page: 'demo_elsewhere' })

    expect(has(head, '[data-id="hilos-page-tabs"]')).toBe(true)
    expect(
      root(head).querySelectorAll('[data-id="hilos-page-tabs"] [aria-current]'),
    ).toHaveLength(0)
    expect(has(head, '.nav-link.active')).toBe(false)
  })

  it("does not let the link's own path match mark another tab", () => {
    // The router already stands at the next tab's address while this screen
    // still shows the previous page: the head marks the page it was given.
    const head = mountHead(
      { page: 'demo_section' },
      routerAt('/hilos/demo/list'),
    )

    expect(tab(head, 'demo_section_list').hasAttribute('aria-current')).toBe(
      false,
    )
    expect(tab(head, 'demo_section_list').classList).not.toContain('active')
    expect(tab(head, 'demo_section').getAttribute('aria-current')).toBe('page')
    expect(
      root(head).querySelectorAll('[data-id="hilos-page-tabs"] [aria-current]'),
    ).toHaveLength(1)
  })

  it('follows the rendered page, not the path, when the page changes', () => {
    const head = mountHead({ page: 'demo_section' }, routerAt('/hilos/demo'))

    update(head, { page: 'demo_section_gaps' })

    // The router still stands at Overview's address; the head was told Gaps.
    expect(tab(head, 'demo_section_gaps').getAttribute('aria-current')).toBe(
      'page',
    )
    expect(tab(head, 'demo_section').hasAttribute('aria-current')).toBe(false)
    expect(root(head).querySelectorAll('.nav-link.active')).toHaveLength(1)
  })

  it('hides a one-link breadcrumb and a one-tab row but keeps the head', () => {
    const head = mountHead({
      identity: {
        ...SECTION,
        breadcrumb: [{ page: 'demo_section', label: 'Demo section' }],
        pageTabs: [{ page: 'demo_section', label: 'Overview' }],
      },
    })

    expect(has(head, '[data-id="hilos-breadcrumb"]')).toBe(false)
    expect(has(head, '[data-id="hilos-page-tabs"]')).toBe(false)
    expect(get(head, 'h1').textContent?.trim()).toBe('Demo section')
    expect(get(head, '[data-id="hilos-page-head-block"]').classList).toContain(
      'mb-4',
    )
  })

  it('counts the row after the route map drops an unbuilt page and a missing param', () => {
    // An unbuilt page has no route here, and Names needs the code the current
    // route does not carry: one tab is left, so no row.
    const head = mountHead({
      identity: {
        ...SECTION,
        pageTabs: [
          { page: 'demo_section', label: 'Overview' },
          { page: 'demo_section_unbuilt', label: 'Unbuilt' },
          { page: 'demo_entity_names', label: 'Names' },
        ],
      },
    })

    expect(has(head, '[data-id="hilos-page-tabs"]')).toBe(false)
    expect(get(head, 'h1').textContent?.trim()).toBe('Demo section')
  })

  it('keeps the order it was answered in for the tabs the route map leaves', () => {
    const head = mountHead({
      identity: {
        ...SECTION,
        pageTabs: [
          { page: 'demo_section', label: 'Overview' },
          { page: 'demo_section_unbuilt', label: 'Unbuilt' },
          { page: 'demo_section_gaps', label: 'Gaps' },
          { page: 'demo_section_list', label: 'List' },
        ],
      },
    })

    expect(
      [...root(head).querySelectorAll<HTMLElement>(`${ROW} a`)].map(
        (link) => link.dataset['id'],
      ),
    ).toEqual([
      'hilos-page-tab-demo_section',
      'hilos-page-tab-demo_section_gaps',
      'hilos-page-tab-demo_section_list',
    ])
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

    const button = get(head, '[data-id="hilos-page-head-action"]')
    expect(button.tagName).toBe('A')
    expect(button.getAttribute('href')).toBe('/hilos/activity/12')
    expect(button.textContent?.trim()).toBe('Activity')
    expect(button.classList).toContain('btn')
    expect(resolve).toHaveBeenCalledWith('demo_activity', { person: '12' })
    // The caption never builds an address.
    for (const [page] of resolve.mock.calls) {
      expect(page).not.toBe('Activity')
    }
    expect(
      get(head, '[data-id="hilos-page-head-block"]').querySelector(
        '[data-id="hilos-page-head-action"]',
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
      get(head, '[data-id="hilos-page-head-action"]').getAttribute('href'),
    ).toBe('/hilos/demo/list/US/names')
  })

  it('draws no button for an action without an address', () => {
    const missingPage = mountHead({
      action: { page: 'demo_missing', label: 'Nowhere' },
    })
    expect(has(missingPage, '[data-id="hilos-page-head-action"]')).toBe(false)
    expect(root(missingPage).textContent).not.toContain('Nowhere')

    // Its own params are the whole context: the current ones fill no gap.
    const missingParam = mountHead({
      params: { person: '12' },
      action: { page: 'demo_activity', label: 'Activity', params: {} },
    })
    expect(has(missingParam, '[data-id="hilos-page-head-action"]')).toBe(false)
  })

  it('shows only a silent skeleton until the answer arrives', () => {
    const host = mountWithTemplates((page) => {
      page.identity.set(undefined)
      page.action.set({ page: 'demo_section', label: 'Back' })
    })

    const skeleton = get(host, '[data-id="hilos-page-head-skeleton"]')
    expect(skeleton.getAttribute('aria-hidden')).toBe('true')
    expect(skeleton.querySelector('.rounded-3')).not.toBeNull()
    expect(skeleton.querySelector('.rounded-circle')).toBeNull()
    expect(has(host, 'h1')).toBe(false)
    expect(has(host, '[data-id="hilos-breadcrumb"]')).toBe(false)
    expect(has(host, '[data-id="hilos-page-tabs"]')).toBe(false)
    expect(has(host, '[data-id="hilos-page-head-block"]')).toBe(false)
    expect(has(host, '[data-id="hilos-page-head-action"]')).toBe(false)
    expect(has(host, '[data-id^="probe-"]')).toBe(false)
    expect(probesCreated).toBe(0)
    // The outlet announces the wait once; the head adds no second voice.
    expect(has(host, '[role="status"]')).toBe(false)
    expect(has(host, '[aria-live]')).toBe(false)
    expect(root(host).textContent?.trim()).toBe('')

    // The answer lands: the head and the page's templates take its place.
    host.componentInstance.identity.set(ENTITY)
    host.detectChanges()
    expect(has(host, '[data-id="hilos-page-head-skeleton"]')).toBe(false)
    expect(get(host, 'h1').textContent?.trim()).toBe('United States')
    expect(probesCreated).toBe(4)
  })

  it('keeps the supplied skeleton picture decorative until real block content can render', () => {
    const fixture = TestBed.createComponent(PageHeadSkeletonHost)
    fixture.detectChanges()

    const skeleton = get(fixture, '[data-id="hilos-page-head-skeleton"]')
    expect(skeleton.getAttribute('aria-hidden')).toBe('true')
    expect(skeleton.querySelector('[data-id="skeleton-visual"]')).not.toBeNull()
    expect(has(fixture, '[data-id="hilos-page-head-block"]')).toBe(false)
    expect(has(fixture, '[data-id="live-visual"]')).toBe(false)
    expect(has(fixture, '[data-id="live-description"]')).toBe(false)
    expect(has(fixture, 'h1')).toBe(false)

    fixture.componentInstance.identity.set(ENTITY)
    fixture.detectChanges()

    expect(has(fixture, '[data-id="hilos-page-head-skeleton"]')).toBe(false)
    expect(has(fixture, '[data-id="skeleton-visual"]')).toBe(false)
    expect(has(fixture, '[data-id="live-visual"]')).toBe(true)
    expect(has(fixture, '[data-id="live-description"]')).toBe(true)
  })

  it("draws a section's skeleton tile round", () => {
    const head = mountHead({ identity: undefined })

    const skeleton = get(head, '[data-id="hilos-page-head-skeleton"]')
    expect(skeleton.querySelector('.rounded-circle')).not.toBeNull()
    expect(skeleton.querySelector('.rounded-3')).toBeNull()
  })

  it('stays on the skeleton for an identity without an owner', () => {
    const head = mountHead({
      identity: {
        label: SECTION.label,
        lead: SECTION.lead,
        breadcrumb: SECTION.breadcrumb,
        children: [],
      },
      action: { page: 'demo_section', label: 'Back' },
    })

    expect(has(head, '[data-id="hilos-page-head-skeleton"]')).toBe(true)
    expect(has(head, 'h1')).toBe(false)
    expect(has(head, '[data-id="hilos-breadcrumb"]')).toBe(false)
    expect(has(head, '[data-id="hilos-page-head-action"]')).toBe(false)
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

  /** The row's box: its visible width and its scrolled content width. */
  const box = { width: 200, content: 360 }

  /**
   * Give every row a controlled layout before it is drawn: a box `box.width`
   * wide holding `box.content` pixels of tabs. A tab's box is read off the links
   * drawn at the moment it is asked for, and moves left as the row scrolls.
   */
  function layRows(): void {
    box.width = 200
    box.content = 360
    const widthOf = (link: HTMLElement) =>
      WIDTHS[(link.dataset['id'] ?? '').slice(TAB_PREFIX.length)] ?? 0
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

  /**
   * Stand a ResizeObserver up whose callback, targets and disconnects the test
   * holds.
   *
   * @returns Every observer the head creates, in order.
   */
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

  /**
   * The row's scrolling list.
   *
   * @param fixture The mounted head.
   * @returns The list the head scrolls.
   */
  function rowOf(fixture: ComponentFixture<unknown>): HTMLElement {
    return get(fixture, ROW)
  }

  it('centers the current tab once drawn', () => {
    layRows()
    observeResizes()
    const head = mountHead({ page: 'demo_section_gaps' })

    expect(rowOf(head).isConnected).toBe(true)
    // 160 + 0 - (200 - 80) / 2 = 100: the tab's middle at the row's middle.
    expect(rowOf(head).scrollLeft).toBe(100)
  })

  it('centers the current tab when the answer brings the row', () => {
    layRows()
    observeResizes()
    const head = mountHead({ identity: undefined, page: 'demo_section_gaps' })
    expect(has(head, ROW)).toBe(false)

    update(head, { identity: SECTION })

    expect(rowOf(head).scrollLeft).toBe(100)
  })

  it('clamps the scroll to the row limits at both ends', () => {
    layRows()
    observeResizes()
    const first = mountHead({ page: 'demo_section_gaps' })
    const start = rowOf(first)
    expect(start.scrollLeft).toBe(100)
    // 100 + (0 - 100) - (200 - 100) / 2 = -50 lies before the row's start.
    update(first, { page: 'demo_section' })
    expect(start.scrollLeft).toBe(0)
    first.destroy()

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

    update(head, { page: 'demo_section_gaps' })
    expect(row.scrollLeft).toBe(100)

    // The reader scrolls by hand; a rename and a new caption keep the row's
    // keys, and with them the scroll, and so does a render of the same inputs.
    row.scrollLeft = 30
    update(head, {
      identity: {
        ...SECTION,
        pageHeadOwner: { ...SECTION.pageHeadOwner!, label: 'Renamed' },
        pageTabs: SECTION.pageTabs!.map((entry) => ({
          ...entry,
          label: `${entry.label} (2)`,
        })),
      },
    })
    expect(get(head, 'h1').textContent?.trim()).toBe('Renamed')
    expect(row.scrollLeft).toBe(30)
    update(head, { page: 'demo_section_gaps', params: {} })
    expect(row.scrollLeft).toBe(30)

    // A narrower box moves the middle: 160 - (120 - 80) / 2 = 140.
    box.width = 120
    expect(observers).toHaveLength(1)
    expect(observers[0]?.targets).toEqual([row])
    observers[0]?.resize()
    expect(row.scrollLeft).toBe(140)
  })

  it('re-centers when the row changes its tabs', () => {
    layRows()
    observeResizes()
    const head = mountHead({ page: 'demo_section_gaps' })
    const row = rowOf(head)
    expect(row.scrollLeft).toBe(100)

    // Without List, Gaps starts at 100: 100 - (200 - 80) / 2 = 40.
    update(head, {
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
    expect(observers[0]?.targets).toEqual([rowOf(head)])

    // The route map leaves one tab: the row is gone, and so is its observer.
    update(head, {
      identity: {
        ...SECTION,
        pageTabs: [{ page: 'demo_section', label: 'Overview' }],
      },
    })
    expect(has(head, ROW)).toBe(false)
    expect(observers[0]?.disconnect).toHaveBeenCalledTimes(1)

    update(head, { identity: SECTION })
    expect(observers).toHaveLength(2)
    expect(observers[1]?.targets).toEqual([rowOf(head)])
    expect(observers[1]?.disconnect).not.toHaveBeenCalled()
  })

  it('disconnects the observer when destroyed', () => {
    const observers = observeResizes()
    const head = mountHead({ page: 'demo_section' })
    expect(observers).toHaveLength(1)
    expect(observers[0]?.disconnect).not.toHaveBeenCalled()

    head.destroy()

    expect(observers[0]?.disconnect).toHaveBeenCalledTimes(1)
  })

  it('leaves a detached row, and a head without a row, alone', () => {
    layRows()
    const observers = observeResizes()
    const detached = mountHead({ page: 'demo_section_gaps' })
    const row = rowOf(detached)
    root(detached).remove()
    expect(row.isConnected).toBe(false)
    row.scrollLeft = 5
    observers[0]?.resize()
    expect(row.scrollLeft).toBe(5)
    update(detached, { page: 'demo_section_gaps_machine' })
    expect(row.scrollLeft).toBe(5)
    detached.destroy()

    const single = mountHead({
      identity: {
        ...SECTION,
        pageTabs: [{ page: 'demo_section', label: 'Overview' }],
      },
    })
    expect(has(single, 'ul')).toBe(false)
    expect(observers).toHaveLength(1)
  })

  it('leaves the row alone when no tab is current', () => {
    layRows()
    observeResizes()
    const head = mountHead({ page: 'demo_elsewhere' })
    const row = rowOf(head)
    row.scrollLeft = 25

    update(head, { page: 'demo_nowhere' })

    expect(row.scrollLeft).toBe(25)
  })
})
