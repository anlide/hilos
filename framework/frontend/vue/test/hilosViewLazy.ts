// Vue owns each app before mount; VTU supplies only the local props reader.
import { VueWrapper } from '@vue/test-utils'
import {
  createApp,
  h,
  markRaw,
  nextTick,
  shallowRef,
  type Component,
} from 'vue'
import { vi } from 'vitest'
import { hilosAdminViews } from '@hilos/vue/admin-views'

import type { WithHilosViewLazy } from '../../test-support/hilosViewLazyFixtures.js'
import ErrorPage from '../src/ErrorPage.vue'
import HilosView from '../src/HilosView.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

export const withHilosViewLazy: WithHilosViewLazy<Component> = async (
  steps,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const releases: (() => Promise<void>)[] = []
  const component = (id: string, text = id) =>
    markRaw({
      render: () => h('div', { 'data-id': id }, text),
    })
  try {
    await steps({
      main: component('main', 'Leaving page'),
      known: component('known-page', 'Known'),
      skeleton: component('main-skeleton'),
      tracked(id) {
        const mounted = vi.fn()
        return {
          mounted,
          component: markRaw({
            setup() {
              mounted()
              return () => h('div', { 'data-id': id }, id)
            },
          }),
        }
      },
      adminViews: hilosAdminViews,
      async mount(input) {
        const host = document.body.appendChild(document.createElement('div'))
        const pages = shallowRef(input.pages)
        const { router, ...props } = input
        const app = createApp({
          render: () => h(HilosView, { ...props, pages: pages.value }),
        })
        app.provide(hilosRouterKey, router)
        let disposed = false
        const unmount = async () => {
          if (disposed) return
          disposed = true
          try {
            app.unmount()
            await nextTick()
          } finally {
            host.remove()
          }
        }
        releases.push(unmount)
        const errors: unknown[] = []
        app.config.errorHandler = (error) => errors.push(error)
        const instance = app.mount(host)
        app.config.errorHandler = undefined
        if (errors.length) throw errors[0]
        const wrapper = new VueWrapper(app, instance)
        const flush = async (change?: () => void) => {
          change?.()
          await Promise.resolve()
          await nextTick()
          await nextTick()
        }
        await flush()
        return {
          root: host,
          flush,
          async setPages(next) {
            pages.value = next
            await flush()
          },
          unmount,
          errorInput: () => wrapper.getComponent(ErrorPage).props('error'),
        }
      },
    })
  } finally {
    try {
      for (const release of releases.reverse()) await release()
    } finally {
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
