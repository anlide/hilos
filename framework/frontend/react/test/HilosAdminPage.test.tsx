import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { useContext } from 'react'
import type { ReactNode } from 'react'
import {
  HilosPages,
  ScopeManager,
  TableViewportController,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import type { HilosAdminPageProps } from '../src/HilosAdminPage.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { HilosPageHeadingIdContext } from '../src/hilosPageHeadingContext.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** The identity a section answers with: a chain above it and cards below. */
const SECTION_IDENTITY: HilosPageIdentity = {
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

/** The identity a leaf answers with: a chain above it and nothing below. */
const LEAF_IDENTITY: HilosPageIdentity = {
  label: 'Language',
  lead: 'A single language.',
  breadcrumb: [{ page: HilosPages.I18N_LANGUAGE, label: 'Language' }],
  children: [],
}

/**
 * The identity of an owner opted in to the shared head. It still carries a card
 * below it, so a test can see that the shared branch does not draw it.
 */
const OWNER_IDENTITY: HilosPageIdentity = {
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

/** What a table without a title of its own would take its name from. */
function HeadingProbe() {
  const headingId = useContext(HilosPageHeadingIdContext)

  return <span data-id="heading-probe">{headingId}</span>
}

function router(identity: HilosPageIdentity | undefined): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/hilos/${page}`,
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

function renderPage(
  page: string,
  identity: HilosPageIdentity | undefined,
  children?: ReactNode,
) {
  return render(
    <HilosRouterContext.Provider value={router(identity)}>
      <HilosAdminPage page={page}>{children}</HilosAdminPage>
    </HilosRouterContext.Provider>,
  )
}

describe('HilosAdminPage', () => {
  afterEach(cleanup)

  it('renders the heading, breadcrumb, and child cards a section answered with', () => {
    const { container } = renderPage(HilosPages.I18N, SECTION_IDENTITY)
    expect(
      container.querySelector('[data-id="hilos-admin-title"]')?.textContent,
    ).toContain('Internationalization')
    expect(
      container.querySelector('[data-id="hilos-breadcrumb"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id^="hilos-admin-child-"]'),
    ).not.toBeNull()
  })

  it('changes child addresses locally while breadcrumbs and the route keep their params', () => {
    const route = createSignal<PageRouteMatch>({
      page: HilosPages.DAEMON,
      params: { nodeId: 'n1', section: 'daemon' },
      admin: true,
    })
    const currentPath = createSignal('/hilos/daemon')
    const navigator: HilosRouter = {
      ...router({
        label: 'Daemon',
        lead: '',
        breadcrumb: [
          { page: HilosPages.DASHBOARD, label: 'Hilos' },
          { page: HilosPages.DAEMON, label: 'Daemon' },
        ],
        children: [
          {
            page: HilosPages.DAEMON_WORKERS,
            label: 'Workers',
            lead: '',
            icon: null,
          },
        ],
      }),
      currentRoute: route,
      currentPath,
      resolvePath: (page, params = {}) =>
        `/hilos/${params.section}/${params.nodeId}/${page}`,
    }
    const view = (nodeId: string) => (
      <HilosRouterContext.Provider value={navigator}>
        <HilosAdminPage page={HilosPages.DAEMON} childParams={{ nodeId }} />
      </HilosRouterContext.Provider>
    )
    const { container, rerender } = render(view('n2'))
    const child = () =>
      container
        .querySelector(
          `[data-id="hilos-admin-child-${HilosPages.DAEMON_WORKERS}"]`,
        )
        ?.getAttribute('href')
    const breadcrumb = () =>
      container.querySelector('[data-id="hilos-breadcrumb"]')?.innerHTML
    const initialBreadcrumb = breadcrumb()

    expect(child()).toBe(`/hilos/daemon/n2/${HilosPages.DAEMON_WORKERS}`)
    expect(initialBreadcrumb).toContain(
      `/hilos/daemon/n1/${HilosPages.DASHBOARD}`,
    )

    rerender(view('n3'))

    expect(child()).toBe(`/hilos/daemon/n3/${HilosPages.DAEMON_WORKERS}`)
    expect(breadcrumb()).toBe(initialBreadcrumb)
    expect(route.get().params).toEqual({ nodeId: 'n1', section: 'daemon' })
    expect(currentPath.get()).toBe('/hilos/daemon')
  })

  it('renders the empty stub for a leaf', () => {
    const { container } = renderPage(HilosPages.I18N_LANGUAGE, LEAF_IDENTITY)
    expect(
      container.querySelector('[data-id="hilos-admin-empty"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
  })

  it('draws a skeleton and nothing else while the name is still on the wire', () => {
    // The empty h1 under the same data-id is what this rules out: a test could
    // not tell "the name did not arrive" from "the name arrived empty".
    const { container } = renderPage(HilosPages.I18N, undefined)

    expect(
      container.querySelector('[data-id="hilos-admin-title-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-title"]')).toBeNull()
    expect(container.querySelector('[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
    // The page key is internal and never printed, least of all as a heading.
    expect(container.textContent).not.toContain(HilosPages.I18N)
  })

  it('keeps the child cards above the content a page provides', () => {
    const { container } = renderPage(
      HilosPages.I18N,
      SECTION_IDENTITY,
      <div data-id="custom-body">mine</div>,
    )

    const cards = container.querySelector('[data-id="hilos-admin-children"]')
    const body = container.querySelector('[data-id="custom-body"]')
    if (cards === null || body === null) {
      throw new Error(
        'A page with children draws both the cards and its content.',
      )
    }

    // A page with children needs both, so the order is the contract and not an
    // accident: the way onward stays above the content it is offered alongside.
    expect(
      cards.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })

  it("draws no stub under a leaf's own content", () => {
    const { container } = renderPage(
      HilosPages.I18N_LANGUAGE,
      LEAF_IDENTITY,
      <p data-id="leaf-body">One language</p>,
    )

    expect(container.querySelector('[data-id="leaf-body"]')).not.toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
  })

  it('names a declared table that has no title of its own by the page heading', () => {
    const controller = new TableViewportController<{ name: string }>({
      resolve: (raw) => ({ name: String(raw.slots.name) }),
      sendViewport: () => {},
      frame: { search: {}, columns: [{ key: 'name', label: 'Name' }] },
    })
    const { container } = renderPage(
      HilosPages.I18N_LANGUAGE,
      LEAF_IDENTITY,
      <HilosViewportTable
        controller={controller}
        cells={{ name: (row) => row.name }}
      />,
    )

    const heading = container.querySelector('[data-id="hilos-admin-title"]')
    expect(heading?.id).toBeTruthy()
    expect(
      container.querySelector('table')?.getAttribute('aria-labelledby'),
    ).toBe(heading?.id)
    // One name over one table: the table draws no heading of its own repeating it.
    expect(container.querySelector('[data-id="hilos-table-title"]')).toBeNull()
  })
})

describe('HilosAdminPage with the shared head', () => {
  afterEach(cleanup)

  /**
   * Mount the shell over a router that answers with the given identity.
   *
   * @param identity What the page answered with, or undefined before the answer.
   * @param props The page key and the shell's other inputs.
   * @param children The page's own content.
   */
  function renderShell(
    identity: HilosPageIdentity | undefined,
    props: HilosAdminPageProps,
    children?: ReactNode,
  ) {
    return render(
      <HilosRouterContext.Provider value={router(identity)}>
        <HilosAdminPage {...props}>{children}</HilosAdminPage>
      </HilosRouterContext.Provider>,
    )
  }

  it('draws the shared head in place of its own heading, lead and cards when the answer names an owner', () => {
    const { container } = renderPage(HilosPages.I18N, OWNER_IDENTITY)

    expect(
      container.querySelector('[data-id="hilos-page-head"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-title"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
    const headings = container.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(headings[0].textContent).toBe('Internationalization')
    // No input of the page's own: the section keeps the head's icon and lead,
    // and the lead stands once — not again under a heading of the shell's own.
    expect(
      container.querySelector(
        '[data-id="hilos-page-head-block"] .bi-translate',
      ),
    ).not.toBeNull()
    expect(
      container.textContent?.split(
        'Languages, countries, and translation screens.',
      ),
    ).toHaveLength(2)
    expect(
      container
        .querySelector(`[data-id="hilos-page-tab-${HilosPages.I18N}"]`)
        ?.getAttribute('aria-current'),
    ).toBe('page')
    expect(
      container.querySelector('[data-id="hilos-breadcrumb"]'),
    ).not.toBeNull()
    // The page gave no content of its own, so the stub still stands under the head.
    expect(
      container.querySelector('[data-id="hilos-admin-empty"]'),
    ).not.toBeNull()
  })

  it("hands the head its kind, its labels, its action and the page's head inputs", () => {
    const { container } = renderShell(
      OWNER_IDENTITY,
      {
        page: HilosPages.I18N_LANGUAGES,
        headKind: 'entity',
        breadcrumbLabel: 'Path',
        tabsLabel: 'Section pages',
        headAction: { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
        headVisual: <span data-id="probe-tile">IN</span>,
        headMeta: <span data-id="probe-code">i18n</span>,
        headBadges: (
          <span data-id="probe-badge" className="badge">
            Own
          </span>
        ),
        headDescription: <p data-id="probe-description">One line</p>,
      },
      <p data-id="page-body">The body</p>,
    )

    const block = container.querySelector('[data-id="hilos-page-head-block"]')
    for (const probe of ['tile', 'code', 'badge', 'description']) {
      expect(
        block?.querySelector(`[data-id="probe-${probe}"]`),
        `probe-${probe}`,
      ).not.toBeNull()
    }
    // The page's inputs replace the section defaults: no icon, no lead.
    expect(block?.querySelector('.bi-translate')).toBeNull()
    expect(block?.textContent).not.toContain(
      'Languages, countries, and translation screens.',
    )
    expect(
      container
        .querySelector('[data-id="hilos-breadcrumb"]')
        ?.getAttribute('aria-label'),
    ).toBe('Path')
    expect(
      container
        .querySelector('[data-id="hilos-page-tabs"]')
        ?.getAttribute('aria-label'),
    ).toBe('Section pages')
    expect(
      container
        .querySelector(
          `[data-id="hilos-page-tab-${HilosPages.I18N_LANGUAGES}"]`,
        )
        ?.getAttribute('aria-current'),
    ).toBe('page')
    const action = container.querySelector('[data-id="hilos-page-head-action"]')
    expect(action?.textContent).toBe('Countries')
    expect(action?.getAttribute('href')).toBe(
      `/hilos/${HilosPages.I18N_COUNTRIES}`,
    )
    const head = container.querySelector('[data-id="hilos-page-head"]')
    const body = container.querySelector('[data-id="page-body"]')
    if (head === null || body === null) {
      throw new Error('The shell draws both the head and the content.')
    }
    expect(
      head.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(container.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
  })

  it('keeps an omitted head input as the default and lets an explicit null turn it off', () => {
    const omitted = renderShell(OWNER_IDENTITY, { page: HilosPages.I18N })
    const block = () =>
      omitted.container.querySelector('[data-id="hilos-page-head-block"]')
    expect(block()?.querySelector('.bi-translate')).not.toBeNull()
    expect(block()?.textContent).toContain(
      'Languages, countries, and translation screens.',
    )
    omitted.unmount()

    const silenced = renderShell(OWNER_IDENTITY, {
      page: HilosPages.I18N,
      headVisual: null,
      headDescription: null,
    })
    const silencedBlock = silenced.container.querySelector(
      '[data-id="hilos-page-head-block"]',
    )
    expect(silencedBlock?.querySelector('.bi-translate')).toBeNull()
    expect(silencedBlock?.textContent).not.toContain(
      'Languages, countries, and translation screens.',
    )
  })

  it('keeps its own head for an answer without an owner, even when the page names a head kind', () => {
    const { container } = renderShell(SECTION_IDENTITY, {
      page: HilosPages.I18N,
      headKind: 'entity',
      headAction: { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
      headVisual: <span data-id="probe-tile">IN</span>,
    })

    expect(container.querySelector('[data-id="hilos-page-head"]')).toBeNull()
    expect(container.querySelector('[data-id="probe-tile"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-action"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-title"]')?.textContent,
    ).toBe('Internationalization')
    expect(
      container.querySelector(
        `[data-id="hilos-admin-child-${HilosPages.I18N_LANGUAGES}"]`,
      ),
    ).not.toBeNull()
  })

  it('stands on the shared skeleton before the answer only for a page that names its head kind', () => {
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const navigator: HilosRouter = {
      ...router(undefined),
      pageIdentity: identity,
    }
    const { container } = render(
      <HilosRouterContext.Provider value={navigator}>
        <HilosAdminPage page={HilosPages.I18N} headKind="entity" />
      </HilosRouterContext.Provider>,
    )

    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-title-skeleton"]'),
    ).toBeNull()
    expect(container.querySelector('h1')).toBeNull()
    expect(container.querySelector('[data-id="hilos-admin-empty"]')).toBeNull()
    // The kind shapes the skeleton's tile: an entity block, not a section circle.
    expect(
      container.querySelector(
        '[data-id="hilos-page-head-skeleton"] .rounded-3',
      ),
    ).not.toBeNull()

    act(() => {
      identity.set(OWNER_IDENTITY)
    })
    expect(
      container.querySelector('[data-id="hilos-page-head-title"]')?.textContent,
    ).toBe('Internationalization')
    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).toBeNull()

    // The kind never opts the page in: an answer without an owner is legacy.
    act(() => {
      identity.set(SECTION_IDENTITY)
    })
    expect(container.querySelector('[data-id="hilos-page-head"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-title"]')?.textContent,
    ).toBe('Internationalization')
  })

  it('keeps the legacy skeleton before the answer for a page that names no head kind', () => {
    const { container } = renderPage(HilosPages.I18N, undefined)

    expect(
      container.querySelector('[data-id="hilos-admin-title-skeleton"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).toBeNull()
    expect(container.querySelector('[data-id="hilos-page-head"]')).toBeNull()
  })

  it.each([
    ['the shared head', OWNER_IDENTITY, 'hilos-page-head-title'],
    ['its own heading', SECTION_IDENTITY, 'hilos-admin-title'],
  ] as const)(
    "provides the id of %s's one h1 to what it holds",
    (_head, identity, titleId) => {
      const { container } = renderPage(
        HilosPages.I18N,
        identity,
        <HeadingProbe />,
      )

      const headings = container.querySelectorAll('h1')
      expect(headings).toHaveLength(1)
      expect(headings[0].getAttribute('data-id')).toBe(titleId)
      const id = headings[0].id
      expect(id).toBeTruthy()
      expect(
        container.querySelector('[data-id="heading-probe"]')?.textContent,
      ).toBe(id)
    },
  )

  it('names a declared table that has no title of its own by the shared head', () => {
    const controller = new TableViewportController<{ name: string }>({
      resolve: (raw) => ({ name: String(raw.slots.name) }),
      sendViewport: () => {},
      frame: { search: {}, columns: [{ key: 'name', label: 'Name' }] },
    })
    const { container } = renderPage(
      HilosPages.I18N,
      OWNER_IDENTITY,
      <HilosViewportTable
        controller={controller}
        cells={{ name: (row) => row.name }}
      />,
    )

    const heading = container.querySelector('[data-id="hilos-page-head-title"]')
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(heading?.id).toBeTruthy()
    expect(
      container.querySelector('table')?.getAttribute('aria-labelledby'),
    ).toBe(heading?.id)
    // One name over one table: the table draws no heading of its own repeating it.
    expect(container.querySelector('[data-id="hilos-table-title"]')).toBeNull()
  })
})

describe('HilosAdminPage and the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  /** A control on the page that shows what the view mode context carries. */
  function Probe() {
    const viewMode = useContext(HilosAdminViewModeContext)

    return <span data-id="probe">{String(viewMode)}</span>
  }

  function renderWithProbe(identity: HilosPageIdentity = LEAF_IDENTITY) {
    return render(
      <HilosRouterContext.Provider value={router(identity)}>
        <HilosAdminPage page={HilosPages.I18N_LANGUAGE}>
          <Probe />
        </HilosAdminPage>
      </HilosRouterContext.Provider>,
    )
  }

  it('tells what it holds that a guest on a node in the mode is a viewer', () => {
    bindSession().handshake(null, true)

    const { container } = renderWithProbe()
    expect(container.querySelector('[data-id="probe"]')?.textContent).toBe(
      'true',
    )
  })

  it('tells no viewer to a non-admin without the mode or to an admin in it', () => {
    const session = bindSession()

    session.handshake({ id: 7, admin: false }, false)
    const off = renderWithProbe()
    expect(off.container.querySelector('[data-id="probe"]')?.textContent).toBe(
      'false',
    )
    off.unmount()

    session.handshake({ id: 1, admin: true }, true)
    const admin = renderWithProbe()
    expect(
      admin.container.querySelector('[data-id="probe"]')?.textContent,
    ).toBe('false')
  })

  it('tells the viewer to what it holds under the shared head too', () => {
    bindSession().handshake(null, true)

    const { container } = renderWithProbe(OWNER_IDENTITY)
    expect(
      container.querySelector('[data-id="hilos-page-head"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="probe"]')?.textContent).toBe(
      'true',
    )
  })

  it('follows the rights live: given, then taken away', () => {
    const session = bindSession()
    session.handshake({ id: 7, admin: false }, true)
    const { container } = renderWithProbe()
    expect(container.querySelector('[data-id="probe"]')?.textContent).toBe(
      'true',
    )

    act(() => {
      session.handshake({ id: 7, admin: true }, true)
    })
    expect(container.querySelector('[data-id="probe"]')?.textContent).toBe(
      'false',
    )

    act(() => {
      session.handshake({ id: 7, admin: false }, true)
    })
    expect(container.querySelector('[data-id="probe"]')?.textContent).toBe(
      'true',
    )
  })
})
