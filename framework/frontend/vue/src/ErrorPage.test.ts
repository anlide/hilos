// Vue lifecycle and providers for ErrorPage and HilosView's error/auth cases.
import { createApp, h, markRaw, nextTick, shallowRef } from 'vue'
import type { VNode } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  AUTH_SURFACE_HEADING_ID,
  type HilosRouter,
  type PageError,
} from '@hilos/core/base'

import {
  registerErrorPageCases,
  type ErrorPageView,
} from '../../test-support/errorPageCases.js'
import { DELETED } from '../../test-support/errorPageFixtures.js'
import {
  registerPageErrorViewCases,
  type WithPageErrorView,
} from '../../test-support/pageErrorViewCases.js'
import ErrorPage from './ErrorPage.vue'
import HilosView from './HilosView.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

/** Vue's exposed ref API has no corresponding imperative API in React. */
interface ExposedErrorPageView extends ErrorPageView {
  homeLink(): HTMLElement | null
}

/** One app lifetime for this entry's error surface and routed error/auth surface. */
async function withErrorSurfaceApp(
  renderSurface: () => VNode,
  steps: (host: HTMLElement) => Promise<void>,
  router?: HilosRouter,
): Promise<void> {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const host = document.body.appendChild(document.createElement('div'))
  const app = createApp({ render: renderSurface })
  if (router) app.provide(hilosRouterKey, router)
  try {
    const mountErrors: unknown[] = []
    app.config.errorHandler = (failure) => mountErrors.push(failure)
    app.mount(host)
    app.config.errorHandler = undefined
    if (mountErrors.length > 0) throw mountErrors[0]
    await nextTick()
    await nextTick()
    await steps(host)
  } finally {
    try {
      app.unmount()
      await nextTick()
    } finally {
      host.remove()
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}

/** Drive the real ErrorPage props and keep its exposed ref API local. */
async function withErrorPage(
  initialError: PageError,
  steps: (view: ExposedErrorPageView) => Promise<void>,
): Promise<void> {
  const error = shallowRef(initialError)
  const component = shallowRef<InstanceType<typeof ErrorPage> | null>(null)
  await withErrorSurfaceApp(
    () => h(ErrorPage, { error: error.value, ref: component }),
    async (root) => {
      await steps({
        root,
        async update(value) {
          error.value = value
          await nextTick()
        },
        homeLink: () => component.value?.homeLink() ?? null,
      })
    },
  )
}

/** Real outlet and modal; the small project components are controlled slot input. */
const withPageErrorView: WithPageErrorView = async (input, steps) => {
  const pages = {
    user: markRaw({ render: () => h('div', { 'data-id': 'user-page' }) }),
  }
  const authSurface = input.authSurface
    ? markRaw({
        render: () =>
          h(
            'div',
            { 'data-id': 'auth-surface' },
            input.authSurface?.heading === undefined
              ? []
              : [
                  h(
                    'h2',
                    { id: AUTH_SURFACE_HEADING_ID },
                    input.authSurface.heading,
                  ),
                ],
          ),
      })
    : undefined
  const flush = async (change?: () => void): Promise<void> => {
    change?.()
    await nextTick()
    await nextTick()
  }
  await withErrorSurfaceApp(
    () => h(HilosView, { pages, authSurface, authGate: input.authGate }),
    async () => steps({ root: document.body, flush }),
    input.router,
  )
}

registerErrorPageCases('Vue', { describe, it, expect }, withErrorPage)
registerPageErrorViewCases('Vue', { describe, it, expect }, withPageErrorView)

// This is the actual exposed Vue API; the shared cases already prove the link.
describe('ErrorPage Vue exposed API', () => {
  it('hands the anchor of its way home to the shell that draws it', async () => {
    await withErrorPage(DELETED, async (view) => {
      const home = view.root.querySelector('[data-id="page-error-home"]')
      expect(home).not.toBeNull()
      expect(view.homeLink()).toBe(home)
    })
  })
})
