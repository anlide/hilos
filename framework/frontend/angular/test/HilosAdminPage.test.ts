// The Angular peer of vue/src/HilosAdminPage.test.ts and
// react/test/HilosAdminPage.test.tsx: the shell draws the cards to a page's
// children whenever it has any, a page's own projected content beneath them, and
// the stub only for a leaf that projects nothing. The second describe is the
// shared head (HIL-1605): an answer that names an owner draws HilosPageHead in
// place of the shell's own heading, lead and cards, dressed by the page's head
// inputs and templates; the page's head kind only picks the skeleton before the
// answer; the one h1 of either head carries the id the shell provides to what
// it holds. The third describe is the admin view mode (HIL-1261): the shell
// tells what it holds whether a viewer stands here, from the core access, live
// across a grant and a revoke.
import { Component, inject, signal, type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
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
  HilosPageHeadAction,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { injectLookOnly } from '../src/hilosLookOnly.js'
import { HILOS_PAGE_HEADING_ID } from '../src/hilosPageHeadingToken.js'
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

function router(
  identity: HilosPageIdentity | undefined,
  pageIdentity = createSignal(identity),
): HilosRouter {
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
    pageIdentity,
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
  navigator: HilosRouter = router(identity),
): ComponentFixture<T> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: navigator }],
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
  it('changes child addresses locally while keeping breadcrumb params and the URL', () => {
    const navigation = {
      ...router({
        label: 'Daemon',
        lead: '',
        breadcrumb: [
          { page: HilosPages.DAEMON_WORKERS, label: 'Workers' },
          { page: HilosPages.DAEMON, label: 'Daemon' },
        ],
        children: [
          {
            page: HilosPages.DAEMON_AGENTS,
            label: 'Agents',
            lead: '',
            icon: null,
          },
        ],
      }),
      currentRoute: createSignal<PageRouteMatch>({
        page: HilosPages.DAEMON,
        params: { nodeId: 'route-node' },
        admin: true,
      }),
      currentPath: createSignal('/hilos/daemon'),
      resolvePath: (page: string, params: Record<string, string>) =>
        `/hilos/${params.nodeId}/${page}`,
    }
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: navigation }],
    })
    const fixture = TestBed.createComponent(HilosAdminPage)
    fixture.componentRef.setInput('page', HilosPages.DAEMON)
    fixture.componentRef.setInput('childParams', { nodeId: 'first' })
    fixture.detectChanges()
    const child = el(fixture, 'hilos-admin-child-hilos_daemon_agents')!
    expect(child.getAttribute('href')).toContain('/first/')
    fixture.componentRef.setInput('childParams', { nodeId: 'second' })
    fixture.detectChanges()
    expect(child.getAttribute('href')).toContain('/second/')
    expect(
      el(fixture, 'hilos-breadcrumb-hilos_daemon_workers')?.getAttribute(
        'href',
      ),
    ).toContain('/route-node/')
    expect(navigation.currentPath.get()).toBe('/hilos/daemon')
    expect(navigation.currentRoute.get().params).toEqual({
      nodeId: 'route-node',
    })
    fixture.destroy()
  })

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
    // A page that names no head kind stands on the legacy skeleton alone.
    expect(el(fixture, 'hilos-page-head')).toBeNull()
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

/**
 * A page that hands the shared head its kind, labels and action, and the four
 * templates of the block beside its own body; its badge comes and goes.
 */
@Component({
  selector: 'test-admin-head-host',
  imports: [HilosAdminPage],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="entity"
      breadcrumbLabel="Path"
      tabsLabel="Section pages"
      [headAction]="action"
    >
      <ng-template #headVisual>
        <span data-id="probe-tile">IN</span>
      </ng-template>
      <ng-template #headMeta>
        <span data-id="probe-code">i18n</span>
      </ng-template>
      @if (badge()) {
        <ng-template #headBadges>
          <span class="badge" data-id="probe-badge">Own</span>
        </ng-template>
      }
      <ng-template #headDescription>
        <p data-id="probe-description">One line</p>
      </ng-template>
      <p data-id="page-body">The body</p>
    </hilos-admin-page>
  `,
})
class HeadHost {
  page: string = HilosPages.I18N_LANGUAGES
  action: HilosPageHeadAction = {
    page: HilosPages.I18N_COUNTRIES,
    label: 'Countries',
  }
  readonly badge = signal(true)
}

/** A page whose only template is a picture it may take back. */
@Component({
  selector: 'test-admin-visual-host',
  imports: [HilosAdminPage],
  template: `
    <hilos-admin-page [page]="page">
      @if (visual()) {
        <ng-template #headVisual>
          <span data-id="probe-tile">IN</span>
        </ng-template>
      }
      <p data-id="page-body">The body</p>
    </hilos-admin-page>
  `,
})
class VisualHost {
  page: string = HilosPages.I18N
  readonly visual = signal(true)
}

/** A page that names its head kind and projects nothing else. */
@Component({
  selector: 'test-admin-kind-host',
  imports: [HilosAdminPage],
  template: `<hilos-admin-page [page]="page" headKind="entity" />`,
})
class KindHost {
  page: string = HilosPages.I18N
}

/** What a table without a title of its own would take its name from. */
@Component({
  selector: 'test-heading-probe',
  template: `<span data-id="heading-probe">{{ headingId }}</span>`,
})
class HeadingProbe {
  protected readonly headingId = inject(HILOS_PAGE_HEADING_ID)
}

/** A page whose one projected node reads the heading id. */
@Component({
  selector: 'test-admin-heading-host',
  imports: [HilosAdminPage, HeadingProbe],
  template: `
    <hilos-admin-page [page]="page">
      <test-heading-probe />
    </hilos-admin-page>
  `,
})
class HeadingHost {
  page: string = HilosPages.I18N
}

/** A page whose one projected node is a table without a title of its own. */
@Component({
  selector: 'test-admin-table-host',
  imports: [HilosAdminPage, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="controller" />
    </hilos-admin-page>
  `,
})
class TableHost {
  page: string = HilosPages.I18N
  controller = new TableViewportController<{ name: string }>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: () => {},
    frame: { search: {}, columns: [{ key: 'name', label: 'Name' }] },
  })
}

describe('HilosAdminPage with the shared head', () => {
  it('draws the shared head in place of its own heading, lead and cards when the answer names an owner', () => {
    const fixture = mount(BareHost, OWNER_IDENTITY)
    const root = fixture.nativeElement as HTMLElement

    expect(el(fixture, 'hilos-page-head')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-title')).toBeNull()
    expect(el(fixture, 'hilos-admin-children')).toBeNull()
    const headings = root.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]!.getAttribute('data-id')).toBe('hilos-page-head-title')
    expect(headings[0]!.textContent?.trim()).toBe('Internationalization')
    // No template of the page's own: the section keeps the head's icon and lead,
    // and the lead stands once — not again under a heading of the shell's own.
    expect(
      el(fixture, 'hilos-page-head-block')?.querySelector('.bi-translate'),
    ).not.toBeNull()
    expect(
      root.textContent?.split('Languages, countries, and translation screens.'),
    ).toHaveLength(2)
    expect(
      el(fixture, `hilos-page-tab-${HilosPages.I18N}`)?.getAttribute(
        'aria-current',
      ),
    ).toBe('page')
    expect(el(fixture, 'hilos-breadcrumb')?.getAttribute('aria-label')).toBe(
      'Breadcrumb',
    )
    expect(el(fixture, 'hilos-page-tabs')?.getAttribute('aria-label')).toBe(
      'Page sections',
    )
    // The page projected nothing of its own, so the stub still stands under the head.
    expect(el(fixture, 'hilos-admin-empty')).not.toBeNull()
  })

  it("hands the head its kind, its labels, its action and the page's templates", () => {
    const fixture = mount(HeadHost, OWNER_IDENTITY)

    const block = el(fixture, 'hilos-page-head-block')
    for (const probe of ['tile', 'code', 'badge', 'description']) {
      expect(
        block?.querySelector(`[data-id="probe-${probe}"]`),
        `probe-${probe}`,
      ).not.toBeNull()
    }
    // The page's templates replace the section defaults: no icon, no lead.
    expect(block?.querySelector('.bi-translate')).toBeNull()
    expect(block?.textContent).not.toContain(
      'Languages, countries, and translation screens.',
    )
    expect(el(fixture, 'hilos-breadcrumb')?.getAttribute('aria-label')).toBe(
      'Path',
    )
    expect(el(fixture, 'hilos-page-tabs')?.getAttribute('aria-label')).toBe(
      'Section pages',
    )
    expect(
      el(fixture, `hilos-page-tab-${HilosPages.I18N_LANGUAGES}`)?.getAttribute(
        'aria-current',
      ),
    ).toBe('page')
    const action = el(fixture, 'hilos-page-head-action')
    expect(action?.textContent?.trim()).toBe('Countries')
    expect(action?.getAttribute('href')).toBe(
      `/hilos/${HilosPages.I18N_COUNTRIES}`,
    )
    const head = el(fixture, 'hilos-page-head')
    const body = el(fixture, 'page-body')
    if (head === null || body === null) {
      throw new Error('The shell draws both the head and the content.')
    }
    expect(
      head.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(el(fixture, 'hilos-admin-empty')).toBeNull()

    // A template the page takes back leaves the block; the others stay.
    fixture.componentInstance.badge.set(false)
    fixture.detectChanges()
    expect(el(fixture, 'probe-badge')).toBeNull()
    expect(block?.querySelector('[data-id="probe-tile"]')).not.toBeNull()
  })

  it('gives the default back when the page takes its template away', () => {
    const fixture = mount(VisualHost, OWNER_IDENTITY)
    const block = () => el(fixture, 'hilos-page-head-block')

    expect(block()?.querySelector('[data-id="probe-tile"]')).not.toBeNull()
    expect(block()?.querySelector('.bi-translate')).toBeNull()
    // The templates the page did not give keep their defaults.
    expect(block()?.textContent).toContain(
      'Languages, countries, and translation screens.',
    )

    fixture.componentInstance.visual.set(false)
    fixture.detectChanges()
    expect(el(fixture, 'probe-tile')).toBeNull()
    expect(block()?.querySelector('.bi-translate')).not.toBeNull()
  })

  it('keeps its own head for an answer without an owner, even when the page names a head kind and templates', () => {
    const fixture = mount(HeadHost, SECTION_IDENTITY)

    expect(el(fixture, 'hilos-page-head')).toBeNull()
    for (const probe of ['tile', 'code', 'badge', 'description']) {
      expect(el(fixture, `probe-${probe}`), `probe-${probe}`).toBeNull()
    }
    expect(el(fixture, 'hilos-page-head-action')).toBeNull()
    expect(el(fixture, 'hilos-admin-title')?.textContent?.trim()).toBe(
      'Internationalization',
    )
    expect(
      el(fixture, `hilos-admin-child-${HilosPages.I18N_LANGUAGES}`),
    ).not.toBeNull()
    // The legacy breadcrumb keeps its own name: the head's label is not its.
    expect(el(fixture, 'hilos-breadcrumb')).not.toBeNull()
    expect(
      el(fixture, 'hilos-breadcrumb')?.getAttribute('aria-label'),
    ).not.toBe('Path')
    expect(el(fixture, 'page-body')).not.toBeNull()
  })

  it('stands on the shared skeleton before the answer only for a page that names its head kind', () => {
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const fixture = mount(KindHost, undefined, router(undefined, identity))
    const root = fixture.nativeElement as HTMLElement

    expect(el(fixture, 'hilos-page-head-skeleton')).not.toBeNull()
    expect(el(fixture, 'hilos-admin-title-skeleton')).toBeNull()
    expect(root.querySelector('h1')).toBeNull()
    expect(el(fixture, 'hilos-admin-empty')).toBeNull()
    // The kind shapes the skeleton's tile: an entity block, not a section circle.
    expect(
      el(fixture, 'hilos-page-head-skeleton')?.querySelector('.rounded-3'),
    ).not.toBeNull()

    identity.set(OWNER_IDENTITY)
    fixture.detectChanges()
    expect(el(fixture, 'hilos-page-head-title')?.textContent?.trim()).toBe(
      'Internationalization',
    )
    expect(el(fixture, 'hilos-page-head-skeleton')).toBeNull()

    // The kind never opts the page in: an answer without an owner is legacy.
    identity.set(SECTION_IDENTITY)
    fixture.detectChanges()
    expect(el(fixture, 'hilos-page-head')).toBeNull()
    expect(el(fixture, 'hilos-admin-title')?.textContent?.trim()).toBe(
      'Internationalization',
    )
    expect(
      el(fixture, `hilos-admin-child-${HilosPages.I18N_LANGUAGES}`),
    ).not.toBeNull()
  })

  it.each([
    ['the shared head', OWNER_IDENTITY, 'hilos-page-head-title'],
    ['its own heading', SECTION_IDENTITY, 'hilos-admin-title'],
  ] as const)(
    "provides the id of %s's one h1 to what it holds",
    (_head, identity, titleId) => {
      const fixture = mount(HeadingHost, identity)

      const headings = (fixture.nativeElement as HTMLElement).querySelectorAll(
        'h1',
      )
      expect(headings).toHaveLength(1)
      expect(headings[0]!.getAttribute('data-id')).toBe(titleId)
      const id = headings[0]!.id
      expect(id).toBeTruthy()
      expect(el(fixture, 'heading-probe')?.textContent).toBe(id)
    },
  )

  it('names a table that has no title of its own by the shared head', () => {
    const fixture = mount(TableHost, OWNER_IDENTITY)
    const root = fixture.nativeElement as HTMLElement

    const heading = el(fixture, 'hilos-page-head-title')
    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(heading?.id).toBeTruthy()
    expect(root.querySelector('table')?.getAttribute('aria-labelledby')).toBe(
      heading?.id,
    )
    // One name over one table: the table draws no heading of its own repeating it.
    expect(root.querySelector('[id^="hilos-table-title-"]')).toBeNull()
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

  it('tells the viewer to what it holds under the shared head too', () => {
    bindSession().handshake(null, true)
    const fixture = mount(ProbeHost, OWNER_IDENTITY)

    expect(el(fixture, 'hilos-page-head')).not.toBeNull()
    expect(probe(fixture)).toBe('true')
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
