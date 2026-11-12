// Test-owned Dashboard answers and native mount inputs.
import {
  HilosPages,
  createSignal,
  type HilosDashboardSection,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'

/** The dashboard's own catalog identity, independent of its card answer. */
export const IDENTITY: HilosPageIdentity = {
  label: 'Hilos',
  lead: 'Administrative sections for the project.',
  breadcrumb: [],
  children: [],
}

/** The original one-group answer, also used beside a project's own area. */
export const FRAMEWORK_SECTIONS: HilosDashboardSection[] = [
  {
    title: 'Access & identity',
    description: 'Users and the roles that grant them panel access.',
    items: [
      {
        page: HilosPages.USERS,
        label: 'Users',
        lead: 'Application users and panel operators.',
        icon: 'bi-people',
      },
    ],
  },
]

/** Equal card captions name different pages; the answer supplies their order. */
export const SECTIONS: HilosDashboardSection[] = [
  ...FRAMEWORK_SECTIONS,
  {
    title: 'Chat administration',
    description: 'What this product adds to the panel.',
    items: [
      {
        page: 'admin_users',
        label: 'Users',
        lead: 'Chat users and their access.',
        icon: 'bi-people',
      },
    ],
  },
]

export const PROJECT_SECTION: HilosDashboardSection = {
  title: 'Project Area',
  description: 'Custom cards for this project.',
  items: [
    {
      page: 'project-page',
      label: 'Project Item',
      lead: 'Project card description.',
      icon: 'bi-star',
    },
  ],
}

/** Navigation that the Dashboard wrapper deliberately leaves out of its head. */
export const RICH_IDENTITY: HilosPageIdentity = {
  ...IDENTITY,
  breadcrumb: [
    { page: 'parent', label: 'Parent' },
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
  ],
  children: [{ page: 'child', label: 'Child', lead: '', icon: null }],
  pageTabs: [
    { page: 'tab-1', label: 'Tab 1' },
    { page: 'tab-2', label: 'Tab 2' },
  ],
}

/** Freeze valid typed arrays in place, preserving their original references. */
export function frozenDashboardIdentity(): HilosPageIdentity {
  const breadcrumb: HilosPageIdentity['breadcrumb'] = [
    { page: 'home', label: 'Home' },
  ]
  const children: HilosPageIdentity['children'] = [
    { page: 'sub', label: 'Sub', lead: '', icon: null },
  ]
  const pageTabs: HilosPageIdentity['pageTabs'] = [
    { page: 'tab', label: 'Tab' },
  ]
  Object.freeze(breadcrumb)
  Object.freeze(children)
  Object.freeze(pageTabs)
  return Object.freeze({ ...IDENTITY, breadcrumb, children, pageTabs })
}

/** Writable source handles stay separate from the router's read-only API. */
export function dashboardRouter(
  initialIdentity: HilosPageIdentity | undefined,
  initialSections: HilosDashboardSection[] | undefined,
) {
  const pageIdentity = createSignal(initialIdentity)
  const dashboardSections = createSignal(initialSections)
  const router: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity,
    dashboardSections,
    resolvePath: (page) => (page === 'unrouted' ? undefined : `/hilos/${page}`),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return { router, pageIdentity, dashboardSections }
}

/** Only the Dashboard's existing router and caller content seam. */
export interface DashboardInput {
  router: HilosRouter
  top?: { dataId: string; text: string }
}

/** The adapter renders real components and flushes actual signal updates. */
export interface DashboardView {
  readonly root: HTMLElement
  flush(change: () => void): Promise<void>
  unmount(): Promise<void>
}

export type MountDashboard = (input: DashboardInput) => Promise<DashboardView>
