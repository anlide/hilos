import { mount } from '@vue/test-utils'
import { markRaw } from 'vue'
import { describe, expect, it } from 'vitest'
import { createSignal } from '@hilos/core'
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
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
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
})
