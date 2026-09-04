import { mount } from '@vue/test-utils'
import { markRaw } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { AUTH_SURFACE_HEADING_ID, createSignal } from '@hilos/core'
import type {
  AuthGate,
  HilosRouter,
  PageRouteMatch,
  PageSubscriptionError,
} from '@hilos/core'

import ErrorPage from './ErrorPage.vue'
import HilosView from './HilosView.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

const NOT_FOUND: PageSubscriptionError = {
  page: 'user',
  httpCode: 404,
  errorCode: 'not_found',
  message: 'Resource #9 not found',
}

const FORBIDDEN: PageSubscriptionError = {
  page: 'user',
  httpCode: 403,
  errorCode: 'forbidden',
  message: 'Access forbidden',
}

describe('ErrorPage', () => {
  it('renders the HTTP code and a mapped title', () => {
    const wrapper = mount(ErrorPage, { props: { error: NOT_FOUND } })

    const surface = wrapper.find('[data-id="page-error"]')
    expect(surface.attributes('data-error-code')).toBe('404')
    expect(surface.text()).toContain('404')
    expect(surface.text()).toContain('Page Not Found')
  })

  it('falls back to a generic title for an unmapped status', () => {
    const wrapper = mount(ErrorPage, {
      props: { error: { ...NOT_FOUND, httpCode: 418 } },
    })

    expect(wrapper.find('[data-id="page-error"]').text()).toContain('Error')
  })

  // The 403 row answers every refusal, so it must not name the refused person:
  // an administrator whose privilege was revoked under an open page used to read
  // that they were a guest (HIL-779). The literal lives here on purpose — read
  // from the component, the assertion would follow a rewording silently.
  it('refuses a 403 without calling the reader a guest', () => {
    const wrapper = mount(ErrorPage, { props: { error: FORBIDDEN } })

    const surface = wrapper.find('[data-id="page-error"]')
    expect(surface.attributes('data-error-code')).toBe('403')
    expect(surface.text()).toContain('Forbidden')
    expect(surface.text()).toContain('You do not have access to this page.')
    expect(surface.text()).not.toMatch(/guest/i)
  })
})

function routerWith(pageError: PageSubscriptionError | null): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: 'user',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal<PageSubscriptionError | null>(pageError),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

const PAGES = { user: markRaw({ template: '<div data-id="user-page"></div>' }) }

describe('HilosView page error', () => {
  it('renders the error surface in place of the page when an error is set', () => {
    const wrapper = mount(HilosView, {
      props: { pages: PAGES },
      global: {
        provide: { [hilosRouterKey as symbol]: routerWith(NOT_FOUND) },
      },
    })

    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="user-page"]').exists()).toBe(false)
  })

  it('renders the mapped page when there is no error', () => {
    const wrapper = mount(HilosView, {
      props: { pages: PAGES },
      global: { provide: { [hilosRouterKey as symbol]: routerWith(null) } },
    })

    expect(wrapper.find('[data-id="user-page"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(false)
  })
})

const UNAUTHORIZED: PageSubscriptionError = {
  page: 'user',
  httpCode: 401,
  errorCode: 'unauthorized',
  message: 'Authentication required',
}

const AUTH_SURFACE = markRaw({
  template: '<div data-id="auth-surface"></div>',
})

// The real surface names itself with a heading it owns; this one stands in for
// it, id and all, so the frame has something to point at.
const HEADED_AUTH_SURFACE = markRaw({
  template: `<div data-id="auth-surface"><h2 id="${AUTH_SURFACE_HEADING_ID}">Confirm your email</h2></div>`,
})

// HilosModal teleports to <body>, so these assertions query the document and
// the document has to start each case empty.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

function fakeAuthGate(open = false): AuthGate {
  return {
    modalOpen: createSignal(open),
    requireAuth: () => {},
    dismiss: () => {},
  }
}

describe('HilosView auth gate', () => {
  it('mounts the auth surface in place of ErrorPage on an anonymous 401', () => {
    const wrapper = mount(HilosView, {
      props: {
        pages: PAGES,
        authSurface: AUTH_SURFACE,
        authGate: fakeAuthGate(),
      },
      global: {
        provide: { [hilosRouterKey as symbol]: routerWith(UNAUTHORIZED) },
      },
    })

    expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(false)
  })

  it('still renders ErrorPage for a non-401 error', () => {
    const wrapper = mount(HilosView, {
      props: {
        pages: PAGES,
        authSurface: AUTH_SURFACE,
        authGate: fakeAuthGate(),
      },
      global: {
        provide: { [hilosRouterKey as symbol]: routerWith(NOT_FOUND) },
      },
    })

    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="auth-surface"]').exists()).toBe(false)
  })

  it('falls back to ErrorPage on a 401 when no surface is registered', () => {
    const wrapper = mount(HilosView, {
      props: { pages: PAGES },
      global: {
        provide: { [hilosRouterKey as symbol]: routerWith(UNAUTHORIZED) },
      },
    })

    expect(wrapper.find('[data-id="page-error"]').exists()).toBe(true)
  })

  it('shows the auth surface in a modal when the gate opens', () => {
    mount(HilosView, {
      props: {
        pages: PAGES,
        authSurface: AUTH_SURFACE,
        authGate: fakeAuthGate(true),
      },
      global: {
        provide: { [hilosRouterKey as symbol]: routerWith(null) },
      },
      attachTo: document.body,
    })

    // The modal teleports to <body>; the live page still renders beneath it.
    expect(document.body.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(
      document.body.querySelector('[data-id="auth-surface"]'),
    ).not.toBeNull()
  })

  it('names the modal with the heading the surface draws', () => {
    mount(HilosView, {
      props: {
        pages: PAGES,
        authSurface: HEADED_AUTH_SURFACE,
        authGate: fakeAuthGate(true),
      },
      global: {
        provide: { [hilosRouterKey as symbol]: routerWith(null) },
      },
      attachTo: document.body,
    })

    const dialog = document.body.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-labelledby')).toBe(
      AUTH_SURFACE_HEADING_ID,
    )
    expect(document.getElementById(AUTH_SURFACE_HEADING_ID)?.textContent).toBe(
      'Confirm your email',
    )
    expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
  })

  it('keeps the Sign in name for a surface that carries no heading', () => {
    mount(HilosView, {
      props: {
        pages: PAGES,
        authSurface: AUTH_SURFACE,
        authGate: fakeAuthGate(true),
      },
      global: {
        provide: { [hilosRouterKey as symbol]: routerWith(null) },
      },
      attachTo: document.body,
    })

    // Nothing carries the id, so aria-labelledby resolves to nothing and the
    // accessible name falls through to the label the frame keeps as a safety
    // net for a project surface of its own.
    expect(document.getElementById(AUTH_SURFACE_HEADING_ID)).toBeNull()
    expect(
      document.body
        .querySelector('[data-id="modal"]')
        ?.getAttribute('aria-label'),
    ).toBe('Sign in')
  })
})
