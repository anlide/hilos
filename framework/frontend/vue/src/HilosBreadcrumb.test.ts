import { describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import {
  registerBreadcrumbCases,
  type BreadcrumbInput,
  type BreadcrumbView,
} from '../../test-support/breadcrumbCases.js'
import HilosBreadcrumb from './HilosBreadcrumb.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

/** Mount the real breadcrumb with the caller's optional router context. */
async function mountBreadcrumb({
  crumbs,
  label,
  router,
}: BreadcrumbInput): Promise<BreadcrumbView> {
  const app = createApp({ render: () => h(HilosBreadcrumb, { crumbs, label }) })
  if (router !== undefined) app.provide(hilosRouterKey, router)
  const host = document.body.appendChild(document.createElement('div'))
  try {
    app.mount(host)
    return {
      root: host,
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

registerBreadcrumbCases('Vue', { describe, it, expect }, mountBreadcrumb)
