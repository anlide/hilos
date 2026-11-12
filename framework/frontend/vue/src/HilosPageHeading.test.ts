import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import type { HilosPageIdentity } from '@hilos/core/base'
import {
  createPageHeadingRouter,
  registerPageHeadingCases,
  type PageHeadingView,
} from '../../test-support/pageHeadingCases.js'
import HilosPageHeading from './HilosPageHeading.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

/** Mount the real heading with its injected core signals. */
async function mountHeading(
  identity?: HilosPageIdentity,
): Promise<PageHeadingView> {
  const { router, pageIdentity } = createPageHeadingRouter(identity)
  const host = document.body.appendChild(document.createElement('div'))
  const app = createApp({
    render: () => h(HilosPageHeading, { dataId: 'test-heading' }),
  })
  app.provide(hilosRouterKey, router)
  try {
    app.mount(host)
    return {
      root: host,
      async update(value) {
        pageIdentity.set(value)
        await nextTick()
      },
      async unmount() {
        try {
          app.unmount()
        } finally {
          host.remove()
        }
      },
    }
  } catch (error) {
    try {
      app.unmount()
    } finally {
      host.remove()
    }
    throw error
  }
}

registerPageHeadingCases('Vue', { describe, it, expect }, mountHeading)
