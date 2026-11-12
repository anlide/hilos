// Vue providers, native events and lifecycle for the shared ToastHost union.
import { createApp, nextTick } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import { registerToastHostCases } from '../../test-support/toastHostCases.js'
import type { WithToastHost } from '../../test-support/toastHostFixtures.js'
import HilosToastHost from './HilosToastHost.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

/** Keep the attached host inside cleanup from creation through the last tick. */
const withToastHost: WithToastHost = async ({ store, router }, steps) => {
  const container = document.body.appendChild(document.createElement('div'))
  const previousFocus = document.activeElement
  const app = createApp(HilosToastHost, { store })
  app.provide(hilosRouterKey, router)
  let unmounted = false
  const flush = async (change?: () => void): Promise<void> => {
    change?.()
    await nextTick()
    await nextTick()
  }
  const unmountHost = (): void => {
    if (!unmounted) {
      unmounted = true
      app.unmount()
    }
  }
  try {
    // Finish mounting before surfacing lifecycle errors so unmount can release it.
    const mountErrors: unknown[] = []
    app.config.errorHandler = (error) => mountErrors.push(error)
    app.mount(container)
    app.config.errorHandler = undefined
    if (mountErrors.length > 0) throw mountErrors[0]
    await steps({
      root: container,
      flush,
      async click(target, init) {
        target.dispatchEvent(
          new MouseEvent('click', { bubbles: true, cancelable: true, ...init }),
        )
        await flush()
      },
      async mouseOver(target) {
        target.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }))
        await flush()
      },
      async mouseLeave(target) {
        target.dispatchEvent(new MouseEvent('mouseleave'))
        await flush()
      },
      async unmount() {
        unmountHost()
        await nextTick()
      },
    })
  } finally {
    try {
      unmountHost()
      await nextTick()
    } finally {
      container.remove()
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus()
      }
    }
  }
}

registerToastHostCases('Vue', { describe, it, expect, vi }, withToastHost)
