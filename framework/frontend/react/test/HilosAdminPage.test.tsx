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
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
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
        row={(row) => <td>{row.name}</td>}
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

  function renderWithProbe() {
    return render(
      <HilosRouterContext.Provider value={router(LEAF_IDENTITY)}>
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
