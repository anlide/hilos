// The Angular peer of vue/src/HilosAdminPage.test.ts and
// react/test/HilosAdminPage.test.tsx: the shell draws the cards to a page's
// children whenever it has any, a page's own projected content beneath them, and
// the stub only for a leaf that projects nothing. The second describe is the
// admin view mode (HIL-1261): the shell tells what it holds whether a viewer
// stands here, from the core access, live across a grant and a revoke.
import { Component, type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  HilosPages,
  ScopeManager,
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
import { afterEach, describe, expect, it } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { injectLookOnly } from '../src/hilosLookOnly.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

/** A page that projects nothing into the shell. */
@Component({
  selector: 'test-admin-bare-host',
  imports: [HilosAdminPage],
  template: `<hilos-admin-page [page]="page" />`,
})
class BareHost {
  page: string = HilosPages.I18N
}

/** A page that projects content of its own into the shell. */
@Component({
  selector: 'test-admin-content-host',
  imports: [HilosAdminPage],
  template: `
    <hilos-admin-page [page]="page">
      <p data-id="page-body">Content of this page</p>
    </hilos-admin-page>
  `,
})
class ContentHost {
  page: string = HilosPages.I18N
}

function mount<T>(
  host: Type<T>,
  identity: HilosPageIdentity | undefined,
): ComponentFixture<T> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router(identity) }],
  })
  const fixture = TestBed.createComponent(host)
  fixture.detectChanges()

  return fixture
}

function el(fixture: ComponentFixture<unknown>, id: string): Element | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

describe('HilosAdminPage', () => {
  it('renders the heading, breadcrumb, and child cards a section answered with', () => {
    const fixture = mount(BareHost, SECTION_IDENTITY)

    expect(el(fixture, 'hilos-admin-title')?.textContent).toContain(
      'Internationalization',
    )
    expect(el(fixture, 'hilos-breadcrumb')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-children')).not.toBeNull()
    expect(
      el(fixture, `hilos-admin-child-${HilosPages.I18N_LANGUAGES}`),
    ).not.toBeNull()
  })

  it('renders the empty stub for a leaf', () => {
    const fixture = mount(BareHost, LEAF_IDENTITY)

    expect(el(fixture, 'hilos-admin-empty')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-children')).toBeNull()
  })

  it('draws a skeleton and nothing else while the name is still on the wire', () => {
    // The empty h1 under the same data-id is what this rules out: a test could
    // not tell "the name did not arrive" from "the name arrived empty".
    const fixture = mount(BareHost, undefined)

    expect(el(fixture, 'hilos-admin-title-skeleton')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-title')).toBeNull()
    expect(el(fixture, 'hilos-breadcrumb')).toBeNull()
    expect(el(fixture, 'hilos-admin-empty')).toBeNull()
    // The page key is internal and never printed, least of all as a heading.
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain(
      HilosPages.I18N,
    )
  })

  it('keeps the child cards above the content a page provides', () => {
    const fixture = mount(ContentHost, SECTION_IDENTITY)

    const cards = el(fixture, 'hilos-admin-children')
    const body = el(fixture, 'page-body')
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
    const fixture = mount(ContentHost, LEAF_IDENTITY)

    expect(el(fixture, 'page-body')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-empty')).toBeNull()
    expect(el(fixture, 'hilos-admin-children')).toBeNull()
  })
})

/** A control on the page that shows what the view mode tells it. */
@Component({
  selector: 'test-view-mode-probe',
  template: `<span data-id="probe">{{ lookOnly.locked() }}</span>
    <span data-id="probe-described">{{ lookOnly.describedBy() }}</span>`,
})
class ViewModeProbe {
  protected readonly lookOnly = injectLookOnly()
}

/** A leaf page whose one projected control is the probe. */
@Component({
  selector: 'test-admin-probe-host',
  imports: [HilosAdminPage, ViewModeProbe],
  template: `
    <hilos-admin-page [page]="page">
      <test-view-mode-probe />
    </hilos-admin-page>
  `,
})
class ProbeHost {
  page: string = HilosPages.I18N_LANGUAGE
}

describe('HilosAdminPage and the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
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

  function probe(fixture: ComponentFixture<unknown>): string {
    return el(fixture, 'probe')?.textContent ?? ''
  }

  it('tells what it holds that a guest on a node in the mode is a viewer', () => {
    bindSession().handshake(null, true)
    const fixture = mount(ProbeHost, LEAF_IDENTITY)

    expect(probe(fixture)).toBe('true')
    expect(el(fixture, 'probe-described')?.textContent).toBe(
      'hilos-view-mode-strip-text',
    )
  })

  it('tells no viewer to a non-admin without the mode or to an admin in it', () => {
    const session = bindSession()

    session.handshake({ id: 7, admin: false }, false)
    expect(probe(mount(ProbeHost, LEAF_IDENTITY))).toBe('false')

    TestBed.resetTestingModule()
    session.handshake({ id: 1, admin: true }, true)
    expect(probe(mount(ProbeHost, LEAF_IDENTITY))).toBe('false')
  })

  it('follows the rights live: given, then taken away', () => {
    const session = bindSession()
    session.handshake({ id: 7, admin: false }, true)
    const fixture = mount(ProbeHost, LEAF_IDENTITY)
    expect(probe(fixture)).toBe('true')

    session.handshake({ id: 7, admin: true }, true)
    fixture.detectChanges()
    expect(probe(fixture)).toBe('false')

    session.handshake({ id: 7, admin: false }, true)
    fixture.detectChanges()
    expect(probe(fixture)).toBe('true')
  })
})
