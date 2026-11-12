import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, shallowRef } from 'vue'
import {
  createLinkRouter,
  registerLinkCases,
  type LinkInput,
  type LinkView,
} from '../../test-support/linkCases.js'
import HilosLink from './HilosLink.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

/** Replace native attrs on each render, keeping default distinct from null. */
async function mountLink(input: LinkInput): Promise<LinkView> {
  const value = shallowRef(input)
  const host = document.body.appendChild(document.createElement('div'))
  const app = createApp({
    render() {
      const { to, current } = value.value
      const attrs = current === undefined ? {} : { 'aria-current': current }
      return h(HilosLink, { to, ...attrs }, () => 'Billing')
    },
  })
  app.provide(hilosRouterKey, createLinkRouter())
  try {
    app.mount(host)
    return {
      root: host,
      async update(input) {
        value.value = input
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

registerLinkCases('Vue', { describe, it, expect }, mountLink)
