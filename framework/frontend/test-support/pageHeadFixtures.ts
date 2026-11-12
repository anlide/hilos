// Test-owned PageHead identity, routes and content; no real owner is adopted.
import {
  HilosPages,
  ScopeManager,
  createSignal,
  pageIdentity,
  resolveHilosPath,
  type HilosPageHeadAction,
  type HilosPageIdentity,
  type HilosPathResolver,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'

/** Synthetic routes: no real owner is opted in by these tests. */
const ROUTES: Record<string, string> = {
  [HilosPages.DASHBOARD]: resolveHilosPath(HilosPages.DASHBOARD)!,
  demo_section: '/hilos/demo',
  demo_section_list: '/hilos/demo/list',
  demo_section_gaps: '/hilos/demo/gaps',
  demo_section_gaps_machine: '/hilos/demo/gaps/machine',
  demo_entity: '/hilos/demo/list/{code}',
  demo_entity_names: '/hilos/demo/list/{code}/names',
  demo_activity: '/hilos/activity/{person}',
}

/** Resolve a synthetic key the way the router does: no address, no path. */
export const resolvePath: HilosPathResolver = (page, params = {}) => {
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
export const SECTION: HilosPageIdentity = {
  label: 'Gaps',
  lead: 'What is still untranslated.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
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
export const ENTITY: HilosPageIdentity = {
  label: 'Names',
  lead: 'Its names in every language.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
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

export const ENTITY_PARAMS = { code: 'US' }

/** One piece of caller-owned content, rendered by the native slot API. */
export interface PageHeadPart {
  dataId: string
  text: string
}

/** Only PageHead's existing content extension points. */
export interface PageHeadContent {
  visual?: PageHeadPart
  meta?: PageHeadPart
  badges?: PageHeadPart
  description?: PageHeadPart
  skeletonVisual?: PageHeadPart
}

/** Inputs of the real head, with content translated to each SDK's native API. */
export interface PageHeadInput {
  identity?: HilosPageIdentity
  page: string
  params: Record<string, string>
  resolvePath: HilosPathResolver
  headingId?: string
  titleClass?: string
  kind?: 'section' | 'entity'
  breadcrumbLabel?: string
  tabsLabel?: string
  action?: HilosPageHeadAction
  content?: PageHeadContent
  /** Test-only context, provided through each SDK's native router binding. */
  router?: HilosRouter
}

export const ENTITY_CONTENT: PageHeadContent = {
  visual: { dataId: 'probe-tile', text: 'US' },
  meta: { dataId: 'probe-code', text: 'us' },
  badges: { dataId: 'probe-badge', text: 'Custom' },
  description: { dataId: 'probe-description', text: 'Currency $ USD' },
}

/** A typed router context whose path can disagree with the rendered answer. */
export function pageHeadRouterAt(path: string): HilosRouter {
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

/** The section's input, with absent identity preserved when explicitly given. */
export function sectionHeadInput(
  overrides: Partial<PageHeadInput> = {},
): PageHeadInput {
  return {
    identity: SECTION,
    page: 'demo_section',
    params: {},
    resolvePath,
    ...overrides,
  }
}

/** The same section's entity, addressed by its code. */
export function entityHeadInput(
  overrides: Partial<PageHeadInput> = {},
): PageHeadInput {
  return sectionHeadInput({
    identity: ENTITY,
    page: 'demo_entity',
    params: ENTITY_PARAMS,
    kind: 'entity',
    ...overrides,
  })
}

/** Feed a malformed wire owner through the real core selector. */
export function createMalformedPageHeadAnswer() {
  const scopes = new ScopeManager()
  try {
    const scope = scopes.openPage('demo_section')
    scope.data.set('pageLabel', 'Gaps')
    scope.data.set('pageBreadcrumb', SECTION.breadcrumb)
    scope.data.set('pageHeadOwner', {
      page: 'demo_section',
      label: 'Demo section',
    })
    scope.data.set('pageTabs', SECTION.pageTabs)
    return {
      identity: pageIdentity(scopes).get(),
      dispose: () => scopes.dropPage(),
    }
  } catch (error) {
    scopes.dropPage()
    throw error
  }
}
