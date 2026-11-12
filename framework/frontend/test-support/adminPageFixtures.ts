// Controlled inputs of AdminPage's shell, without a real page outlet.
import {
  createSignal,
  resolveHilosPath,
  HilosPages,
  type HilosPageHeadAction,
  type HilosPageIdentity,
  type HilosPathResolver,
  type HilosRouter,
  type PageRouteMatch,
  type PageSubscriptionError,
  type TableViewportController,
} from '@hilos/core/base'
import type { AdminPageDeletionContent } from './adminPageDeletionFixtures.js'

/** A section that answers with ancestry and one child card. */
export const SECTION_IDENTITY: HilosPageIdentity = {
  label: 'Internationalization',
  lead: 'Languages, countries, and translation screens.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.I18N, label: 'Internationalization' },
  ],
  children: [
    {
      page: HilosPages.I18N_LANGUAGES,
      label: 'Languages',
      lead: 'The languages the product ships in.',
      icon: null,
    },
  ],
}

/** A leaf whose empty state depends on whether the caller supplies content. */
export const LEAF_IDENTITY: HilosPageIdentity = {
  label: 'Language',
  lead: 'A single language.',
  breadcrumb: [{ page: HilosPages.I18N_LANGUAGE, label: 'Language' }],
  children: [],
}

/** An adopted owner that still carries legacy children the shared head hides. */
export const OWNER_IDENTITY: HilosPageIdentity = {
  ...SECTION_IDENTITY,
  pageHeadOwner: {
    page: HilosPages.I18N,
    label: 'Internationalization',
    lead: 'Languages, countries, and translation screens.',
    icon: 'bi-translate',
  },
  pageTabs: [
    { page: HilosPages.I18N, label: 'Overview' },
    { page: HilosPages.I18N_LANGUAGES, label: 'Languages' },
  ],
}

/** Caller-owned content translated into native slots, nodes or templates. */
export interface AdminPagePart {
  dataId: string
  text: string
}

export interface AdminPageHeadContent {
  visual?: AdminPagePart
  meta?: AdminPagePart
  badges?: AdminPagePart
  description?: AdminPagePart
  skeletonVisual?: AdminPagePart
}

export const ADMIN_PAGE_HEAD_CONTENT: AdminPageHeadContent = {
  visual: { dataId: 'probe-tile', text: 'IN' },
  meta: { dataId: 'probe-code', text: 'i18n' },
  badges: { dataId: 'probe-badge', text: 'Own' },
  description: { dataId: 'probe-description', text: 'One line' },
}

/** The minimal row a real untitled table can resolve under this shell. */
export interface AdminPageTableRow {
  name: string
}

/** Only the real shell's inputs used by these scenarios. */
export interface AdminPageInput {
  page: string
  router: HilosRouter
  childParams?: Record<string, string>
  body?: { dataId: string; text: string }
  contextProbe?: 'heading' | 'view-mode'
  table?: TableViewportController<AdminPageTableRow>
  headKind?: 'section' | 'entity'
  headAction?: HilosPageHeadAction
  headTitleClass?: string
  breadcrumbLabel?: string
  tabsLabel?: string
  headContent?: AdminPageHeadContent
  deletion?: AdminPageDeletionContent
}

/** The real component's DOM and native prop/update/destruction boundaries. */
export interface AdminPageView {
  readonly root: HTMLElement
  update(input: AdminPageInput): Promise<void>
  flush(change: () => void): Promise<void>
  unmount(): Promise<void>
}

export type MountAdminPage = (input: AdminPageInput) => Promise<AdminPageView>

/** Keep writable handles separate from the router's read-only signal contract. */
export function adminPageRouter(
  identity: HilosPageIdentity | undefined,
  options: {
    route?: PageRouteMatch
    path?: string
    resolvePath?: HilosPathResolver
  } = {},
) {
  const currentRoute = createSignal<PageRouteMatch>(
    options.route ?? { page: HilosPages.I18N, params: {}, admin: true },
  )
  const currentPath = createSignal(
    options.path ?? resolveHilosPath(HilosPages.I18N)!,
  )
  const pageIdentity = createSignal(identity)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const router: HilosRouter = {
    currentRoute,
    currentPath,
    currentTitle: createSignal(''),
    pageError,
    pageLoading: createSignal(false),
    pageIdentity,
    dashboardSections: createSignal(undefined),
    resolvePath: options.resolvePath ?? resolveHilosPath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return { router, currentRoute, currentPath, pageIdentity, pageError }
}

/** A section input; explicit undefined identity remains the unanswered case. */
export function adminPageInput(
  identity: HilosPageIdentity | undefined,
  overrides: Partial<AdminPageInput> = {},
): AdminPageInput {
  return {
    page: HilosPages.I18N,
    router: adminPageRouter(identity).router,
    ...overrides,
  }
}
