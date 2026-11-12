// Vue owns the app before mounting so even mount failures reach unmount.
import { createApp, h, markRaw, nextTick } from 'vue'
import { AUTH_SURFACE_HEADING_ID } from '@hilos/core/base'

import type { WithHilosViewOutlet } from '../../test-support/hilosViewFixtures.js'
import HilosView from '../src/HilosView.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

export const withHilosViewOutlet: WithHilosViewOutlet = async (
  input,
  steps,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const host = document.body.appendChild(document.createElement('div'))
  const pages = {
    main: markRaw({
      render: () => h('div', { 'data-id': 'main' }, 'Leaving page'),
    }),
    known: markRaw({
      render: () => h('div', { 'data-id': 'known-page' }, 'Known'),
    }),
  }
  const pageSkeletons = input.withSkeletons
    ? {
        main: markRaw({
          render: () => h('div', { 'data-id': 'main-skeleton' }),
        }),
      }
    : undefined
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
  const app = createApp({
    render: () =>
      h(HilosView, {
        pages,
        pageSkeletons,
        authSurface,
        authGate: input.authGate,
      }),
  })
  app.provide(hilosRouterKey, input.router)
  const flush = async (change?: () => void) => {
    change?.()
    await nextTick()
    await nextTick()
  }
  try {
    const mountErrors: unknown[] = []
    app.config.errorHandler = (error) => mountErrors.push(error)
    app.mount(host)
    app.config.errorHandler = undefined
    if (mountErrors.length > 0) throw mountErrors[0]
    await flush()
    await steps({ root: host, flush })
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
