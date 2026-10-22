import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  FocusTrap,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  createAppPageRouter,
  registerFocusReturnFallback,
} from '@hilos/core/base'
import type {
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  PageSubscriptionError,
  ProjectSignal,
} from '@hilos/core/base'
import { defineComponent, h, inject, nextTick } from 'vue'

import ErrorPage from './ErrorPage.vue'
import HilosAdminPage from './HilosAdminPage.vue'
import { useAdminViewMode } from './hilosAdminViewMode.js'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'
import { hilosRouterKey } from './hilosRouterKey.js'

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
const HeadingProbe = defineComponent(() => {
  const headingId = inject(hilosPageHeadingIdKey)

  return () => h('span', { 'data-id': 'heading-probe' }, headingId)
})

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

function mountPage(
  page: string,
  identity: HilosPageIdentity | undefined,
  slots: Record<string, string> = {},
) {
  return mount(HilosAdminPage, {
    props: { page },
    slots,
    global: { provide: { [hilosRouterKey as symbol]: router(identity) } },
  })
}

describe('HilosAdminPage', () => {
  it('renders the heading, breadcrumb, and child cards a section answered with', () => {
    const wrapper = mountPage(HilosPages.I18N, SECTION_IDENTITY)
    expect(wrapper.find('[data-id="hilos-admin-title"]').text()).toContain(
      'Internationalization',
    )
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(true)
  })

  it('renders the empty stub for a leaf', () => {
    const wrapper = mountPage(HilosPages.I18N_LANGUAGE, LEAF_IDENTITY)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
  })

  it('draws a skeleton and nothing else while the name is still on the wire', () => {
    // The empty h1 under the same data-id is what this rules out: a test could
    // not tell "the name did not arrive" from "the name arrived empty".
    const wrapper = mountPage(HilosPages.I18N, undefined)

    expect(
      wrapper.find('[data-id="hilos-admin-title-skeleton"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
    // The page key is internal and never printed, least of all as a heading.
    expect(wrapper.text()).not.toContain(HilosPages.I18N)
  })

  it("draws a page's own content after the child cards rather than in place of them", () => {
    const wrapper = mountPage(HilosPages.I18N, SECTION_IDENTITY, {
      default: '<p data-id="section-body">Figures of this section</p>',
    })

    const cards = wrapper.find('[data-id="hilos-admin-children"]')
    const body = wrapper.find('[data-id="section-body"]')
    expect(cards.exists()).toBe(true)
    expect(body.exists()).toBe(true)
    // A page with children needs both, so the order is the contract and not an
    // accident: the way onward stays above the content it is offered alongside.
    expect(
      cards.element.compareDocumentPosition(body.element) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })

  it("draws no stub under a leaf's own content", () => {
    const wrapper = mountPage(HilosPages.I18N_LANGUAGE, LEAF_IDENTITY, {
      default: '<p data-id="leaf-body">One language</p>',
    })

    expect(wrapper.find('[data-id="leaf-body"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
  })

  it('merges child params for cards while keeping breadcrumbs on route params', async () => {
    const identity: HilosPageIdentity = {
      label: 'Daemon',
      lead: '',
      breadcrumb: [
        { page: HilosPages.DAEMON_WORKERS, label: 'Route node' },
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
    }
    const matcher = createAppPageRouter({})
    const navigation: HilosRouter = {
      ...router(identity),
      currentRoute: createSignal<PageRouteMatch>({
        page: HilosPages.DAEMON,
        params: { nodeId: 'route-node' },
        admin: true,
      }),
      resolvePath: matcher.path,
    }
    const wrapper = mount(HilosAdminPage, {
      props: { page: HilosPages.DAEMON },
      global: { provide: { [hilosRouterKey as symbol]: navigation } },
    })
    const child = () =>
      wrapper.find('[data-id="hilos-admin-child-hilos_daemon_workers"]')
    expect(child().attributes('href')).toBe('/hilos/daemon/route-node/workers')
    await wrapper.setProps({ childParams: { nodeId: 'selected-node' } })
    expect(child().attributes('href')).toBe(
      '/hilos/daemon/selected-node/workers',
    )
    expect(
      wrapper.find('[data-id="hilos-breadcrumb"] a').attributes('href'),
    ).toBe('/hilos/daemon/route-node/workers')
    await wrapper.setProps({ childParams: { nodeId: 'next' } })
    expect(child().attributes('href')).toBe('/hilos/daemon/next/workers')
    const nodeId = 'node /?#%2F🌍'
    await wrapper.setProps({ childParams: { nodeId } })
    const href = child().attributes('href')!
    expect(href).toBe(`/hilos/daemon/${encodeURIComponent(nodeId)}/workers`)
    expect(matcher.match(href).params).toEqual({ nodeId })
    expect(
      wrapper.find('[data-id="hilos-breadcrumb"] a').attributes('href'),
    ).toBe('/hilos/daemon/route-node/workers')
    await wrapper.setProps({ childParams: undefined })
    expect(child().attributes('href')).toBe('/hilos/daemon/route-node/workers')
    wrapper.unmount()
  })
})

describe('HilosAdminPage with the shared head', () => {
  it('draws the shared head in place of its own heading, lead and cards when the answer names an owner', () => {
    const wrapper = mountPage(HilosPages.I18N, OWNER_IDENTITY)

    expect(wrapper.find('[data-id="hilos-page-head"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    const headings = wrapper.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].attributes('data-id')).toBe('hilos-page-head-title')
    expect(headings[0].text()).toBe('Internationalization')
    // No slot of the page's own: the section keeps the head's icon and lead,
    // and the lead stands once — not again under a heading of the shell's own.
    expect(
      wrapper.find('[data-id="hilos-page-head-block"] .bi-translate').exists(),
    ).toBe(true)
    expect(
      wrapper.text().split('Languages, countries, and translation screens.'),
    ).toHaveLength(2)
    expect(
      wrapper
        .get(`[data-id="hilos-page-tab-${HilosPages.I18N}"]`)
        .attributes('aria-current'),
    ).toBe('page')
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(true)
  })

  it("hands the head its kind, its labels, its action and the page's head slots", () => {
    const wrapper = mount(HilosAdminPage, {
      props: {
        page: HilosPages.I18N_LANGUAGES,
        headKind: 'entity',
        breadcrumbLabel: 'Path',
        tabsLabel: 'Section pages',
        headAction: { page: HilosPages.I18N_COUNTRIES, label: 'Countries' },
      },
      slots: {
        'head-visual': '<span data-id="probe-tile">IN</span>',
        'head-meta': '<span data-id="probe-code">i18n</span>',
        'head-badges': '<span data-id="probe-badge" class="badge">Own</span>',
        'head-description': '<p data-id="probe-description">One line</p>',
        default: '<p data-id="page-body">The body</p>',
      },
      global: {
        provide: { [hilosRouterKey as symbol]: router(OWNER_IDENTITY) },
      },
    })

    const block = wrapper.get('[data-id="hilos-page-head-block"]')
    for (const probe of ['tile', 'code', 'badge', 'description']) {
      expect(block.find(`[data-id="probe-${probe}"]`).exists()).toBe(true)
    }
    // The page's slots replace the section defaults: no icon, no lead.
    expect(block.find('.bi-translate').exists()).toBe(false)
    expect(block.text()).not.toContain(
      'Languages, countries, and translation screens.',
    )
    expect(
      wrapper.get('[data-id="hilos-breadcrumb"]').attributes('aria-label'),
    ).toBe('Path')
    expect(
      wrapper.get('[data-id="hilos-page-tabs"]').attributes('aria-label'),
    ).toBe('Section pages')
    expect(
      wrapper
        .get(`[data-id="hilos-page-tab-${HilosPages.I18N_LANGUAGES}"]`)
        .attributes('aria-current'),
    ).toBe('page')
    const action = wrapper.get('[data-id="hilos-page-head-action"]')
    expect(action.text()).toBe('Countries')
    expect(action.attributes('href')).toBe(
      `/hilos/${HilosPages.I18N_COUNTRIES}`,
    )
    const body = wrapper.get('[data-id="page-body"]')
    expect(
      wrapper
        .get('[data-id="hilos-page-head"]')
        .element.compareDocumentPosition(body.element) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
  })

  it('keeps its own head for an answer without an owner, even when the page names a head kind', () => {
    const wrapper = mount(HilosAdminPage, {
      props: { page: HilosPages.I18N, headKind: 'entity' },
      slots: { 'head-visual': '<span data-id="probe-tile">IN</span>' },
      global: {
        provide: { [hilosRouterKey as symbol]: router(SECTION_IDENTITY) },
      },
    })

    expect(wrapper.find('[data-id="hilos-page-head"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="probe-tile"]').exists()).toBe(false)
    expect(wrapper.get('[data-id="hilos-admin-title"]').text()).toBe(
      'Internationalization',
    )
    expect(
      wrapper
        .find(`[data-id="hilos-admin-child-${HilosPages.I18N_LANGUAGES}"]`)
        .exists(),
    ).toBe(true)
  })

  it('stands on the shared skeleton before the answer only for a page that names its head kind', async () => {
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const wrapper = mount(HilosAdminPage, {
      props: { page: HilosPages.I18N, headKind: 'entity' },
      global: {
        provide: {
          [hilosRouterKey as symbol]: {
            ...router(undefined),
            pageIdentity: identity,
          },
        },
      },
    })

    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(
      wrapper.find('[data-id="hilos-admin-title-skeleton"]').exists(),
    ).toBe(false)
    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)

    identity.set(OWNER_IDENTITY)
    await nextTick()
    expect(wrapper.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Internationalization',
    )
    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      false,
    )

    // The kind never opts the page in: an answer without an owner is legacy.
    identity.set(SECTION_IDENTITY)
    await nextTick()
    expect(wrapper.find('[data-id="hilos-page-head"]').exists()).toBe(false)
    expect(wrapper.get('[data-id="hilos-admin-title"]').text()).toBe(
      'Internationalization',
    )
  })

  it.each([
    ['the shared head', OWNER_IDENTITY],
    ['its own heading', SECTION_IDENTITY],
  ] as const)(
    "provides the id of %s's one h1 to what it holds",
    (_head, identity) => {
      const wrapper = mount(HilosAdminPage, {
        props: { page: HilosPages.I18N },
        slots: { default: () => h(HeadingProbe) },
        global: { provide: { [hilosRouterKey as symbol]: router(identity) } },
      })

      const headings = wrapper.findAll('h1')
      expect(headings).toHaveLength(1)
      const id = headings[0].attributes('id')
      expect(id).toBeTruthy()
      expect(wrapper.get('[data-id="heading-probe"]').text()).toBe(id)
    },
  )
})

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

  /** A control on the page that shows what the view mode tells it. */
  const Probe = defineComponent(() => {
    const viewMode = useAdminViewMode()

    return () => h('span', { 'data-id': 'probe' }, String(viewMode.value))
  })

  function mountWithProbe(identity: HilosPageIdentity = LEAF_IDENTITY) {
    return mount(HilosAdminPage, {
      props: { page: HilosPages.I18N_LANGUAGE },
      slots: { default: () => h(Probe) },
      global: {
        provide: { [hilosRouterKey as symbol]: router(identity) },
      },
    })
  }

  it('tells what it holds that a guest on a node in the mode is a viewer', () => {
    bindSession().handshake(null, true)

    expect(mountWithProbe().find('[data-id="probe"]').text()).toBe('true')
  })

  it('tells no viewer to a non-admin without the mode or to an admin in it', () => {
    const session = bindSession()

    session.handshake({ id: 7, admin: false }, false)
    expect(mountWithProbe().find('[data-id="probe"]').text()).toBe('false')

    session.handshake({ id: 1, admin: true }, true)
    expect(mountWithProbe().find('[data-id="probe"]').text()).toBe('false')
  })

  it('tells the viewer to what it holds under the shared head too', () => {
    bindSession().handshake(null, true)
    const wrapper = mountWithProbe(OWNER_IDENTITY)

    expect(wrapper.find('[data-id="hilos-page-head"]').exists()).toBe(true)
    expect(wrapper.get('[data-id="probe"]').text()).toBe('true')
  })

  it('follows the rights live: given, then taken away', async () => {
    const session = bindSession()
    session.handshake({ id: 7, admin: false }, true)
    const wrapper = mountWithProbe()
    expect(wrapper.find('[data-id="probe"]').text()).toBe('true')

    session.handshake({ id: 7, admin: true }, true)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-id="probe"]').text()).toBe('false')

    session.handshake({ id: 7, admin: false }, true)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-id="probe"]').text()).toBe('true')
  })
})

describe('HilosAdminPage under a deleted resource', () => {
  /** The live 404 of a section whose resource was deleted while it stood open. */
  const DELETED: PageSubscriptionError = {
    page: HilosPages.I18N,
    httpCode: 404,
    errorCode: 'not_found',
    title: 'Page Not Found',
    message: 'The page you are looking for does not exist.',
    transitionMessage: 'It was deleted just now.',
  }

  const wrappers: { unmount(): void }[] = []

  afterEach(() => {
    for (const wrapper of wrappers.splice(0)) {
      wrapper.unmount()
    }
    document.body.innerHTML = ''
  })

  /**
   * Mount the shell alone, outside any outlet, over a page error the test sets.
   * Its #modals holds a probe of what the shell provides, counting its mounts.
   *
   * @param preserve Whether the page opts in.
   */
  function standalone(preserve: boolean) {
    const pageError = createSignal<PageSubscriptionError | null>(null)
    const modalsMounted = vi.fn()
    const ModalsProbe = defineComponent(() => {
      modalsMounted()
      const headingId = inject(hilosPageHeadingIdKey)
      const viewMode = useAdminViewMode()

      return () =>
        h(
          'span',
          { 'data-id': 'modals-probe' },
          `${headingId} ${viewMode.value}`,
        )
    })
    const wrapper = mount(HilosAdminPage, {
      props: { page: HilosPages.I18N, preserveModalDraftsOnDelete: preserve },
      slots: {
        default: () =>
          h('button', { type: 'button', 'data-id': 'opener' }, 'Rename'),
        modals: () => h(ModalsProbe),
      },
      global: {
        provide: {
          [hilosRouterKey as symbol]: {
            ...router(SECTION_IDENTITY),
            pageError,
          },
        },
      },
      attachTo: document.body,
    })
    wrappers.push(wrapper)

    return { wrapper, pageError, modalsMounted }
  }

  /**
   * Open a trap from an opener in the shell while a surface around the shell
   * offers its own fallback. Where focus lands on close names whose offer the
   * trap took when the opener is gone.
   *
   * @param opener The control that opens the dialog.
   */
  function trapFrom(opener: HTMLElement) {
    const outer = document.createElement('button')
    outer.type = 'button'
    document.body.append(outer)
    const releaseOuter = registerFocusReturnFallback(document.body, () => outer)
    opener.focus()
    const dialog = document.createElement('div')
    dialog.tabIndex = -1
    document.body.append(dialog)
    const trap = new FocusTrap()
    trap.activate(dialog, 'dialog')

    return {
      outer,
      close: () => {
        trap.release()
        releaseOuter()
      },
    }
  }

  it('draws the page error in place of its head, cards and content, and keeps #modals', async () => {
    const { wrapper, pageError, modalsMounted } = standalone(true)
    const probe = wrapper.get('[data-id="modals-probe"]')
    const provided = probe.text()
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(true)

    pageError.set(DELETED)
    await nextTick()

    const shell = wrapper.get('[data-id="hilos-admin-page"]')
    expect(shell.findAllComponents(ErrorPage)).toHaveLength(1)
    expect(shell.get('[data-id="page-error-transition-message"]').text()).toBe(
      'It was deleted just now.',
    )
    for (const gone of [
      'hilos-breadcrumb',
      'hilos-admin-title',
      'hilos-admin-children',
      'opener',
    ]) {
      expect(wrapper.find(`[data-id="${gone}"]`).exists()).toBe(false)
    }
    const headings = wrapper.findAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0].text()).toBe('404')
    expect(wrapper.get('[data-id="modals-probe"]').element).toBe(probe.element)
    expect(modalsMounted).toHaveBeenCalledTimes(1)
    expect(probe.text()).toBe(provided)
    expect(provided).toMatch(/^\S+ false$/)
  })

  it('keeps its content under a page error without the opt-in', async () => {
    const { wrapper, pageError } = standalone(false)

    pageError.set(DELETED)
    await nextTick()

    expect(wrapper.findComponent(ErrorPage).exists()).toBe(false)
    expect(wrapper.get('[data-id="hilos-admin-title"]').text()).toBe(
      'Internationalization',
    )
    expect(wrapper.find('[data-id="opener"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="modals-probe"]').exists()).toBe(true)
  })

  it('reads no page error without the opt-in', () => {
    // A navigator that carries no page error at all, as a page's own test
    // may build one: the shell without the opt-in must not reach for it.
    const navigation = {
      ...router(SECTION_IDENTITY),
      pageError: undefined,
    } as unknown as HilosRouter
    const wrapper = mount(HilosAdminPage, {
      props: { page: HilosPages.I18N },
      global: { provide: { [hilosRouterKey as symbol]: navigation } },
    })
    wrappers.push(wrapper)

    expect(wrapper.get('[data-id="hilos-admin-title"]').text()).toBe(
      'Internationalization',
    )
  })

  it('returns focus to its Back to home once the opener went away with the content', async () => {
    const { wrapper, pageError } = standalone(true)
    const opener = wrapper.get('[data-id="opener"]').element as HTMLElement
    const { close } = trapFrom(opener)

    pageError.set(DELETED)
    await nextTick()
    close()

    expect(opener.isConnected).toBe(false)
    expect(document.activeElement).toBe(
      wrapper.get('[data-id="page-error-home"]').element,
    )
  })

  it('leaves a lost opener to the surface around it without the opt-in', () => {
    const { wrapper } = standalone(false)
    const opener = wrapper.get('[data-id="opener"]').element as HTMLElement
    const { outer, close } = trapFrom(opener)

    opener.remove()
    close()

    expect(document.activeElement).toBe(outer)
  })

  it('stops answering for its root once unmounted', () => {
    const { wrapper } = standalone(true)
    const section = wrapper.element as HTMLElement
    const opener = section.querySelector<HTMLElement>('[data-id="opener"]')!
    // The request a dialog's trap sends from its opener (core/dom/focusTrap.ts),
    // sent here with no owner around the shell. An unmounted shell's ErrorPage
    // is gone, so a leaked offer would answer null and lose to any surface
    // around it: only whether the request was answered at all tells it apart.
    const answered = (): boolean => {
      const request: { fallback?: () => HTMLElement | null } = {}
      opener.dispatchEvent(
        new CustomEvent('hilos-focus-return-request', {
          bubbles: true,
          detail: request,
        }),
      )

      return request.fallback !== undefined
    }
    expect(answered()).toBe(true)
    wrappers.splice(0)
    wrapper.unmount()
    // The old root back in the document with its opener: only an offer the
    // shell failed to withdraw could still answer for it.
    document.body.append(section)
    expect(answered()).toBe(false)
    const { outer, close } = trapFrom(opener)

    opener.remove()
    close()

    expect(document.activeElement).toBe(outer)
  })
})
