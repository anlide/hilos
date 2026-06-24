import { mount } from '@vue/test-utils'
import { markRaw } from 'vue'
import { describe, expect, it } from 'vitest'
import { createSignal } from '@hilos/core'
import type {
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
    currentRoute: createSignal<PageRouteMatch>({ page: 'user', params: {} }),
    pageError: createSignal<PageSubscriptionError | null>(pageError),
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
