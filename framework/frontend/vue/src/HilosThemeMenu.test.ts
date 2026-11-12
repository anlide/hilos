// Vue mounting and lifecycle for the shared ThemeMenu scenarios.
import { HilosLayout, HilosThemeMenu } from '@hilos/vue/base'
import { describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, type App } from 'vue'

import { registerThemeMenuCases } from '../../test-support/themeMenuCases.js'
import {
  withThemeMenuState,
  type WithThemeMenu,
} from '../../test-support/themeMenuFixtures.js'

const withThemeMenu: WithThemeMenu = async (input, steps) => {
  await withThemeMenuState(input, async (server) => {
    const host = document.body.appendChild(document.createElement('div'))
    let app: App | undefined
    let destroyed = false
    const unmount = async (): Promise<void> => {
      if (destroyed) return
      destroyed = true
      try {
        app?.unmount()
        await nextTick()
      } finally {
        host.remove()
      }
    }
    try {
      app = createApp({
        render: () =>
          input.shell
            ? h(
                HilosLayout,
                {
                  connection: server.connection,
                  allowDisconnectedInteraction:
                    input.allowDisconnectedInteraction,
                },
                {
                  default: () => h('p', { 'data-id': 'page-body' }, 'Page'),
                  user: () => h('span', { 'data-id': 'user-slot' }, 'Ada'),
                },
              )
            : h(HilosThemeMenu),
      })
      const mountErrors: unknown[] = []
      app.config.errorHandler = (failure) => mountErrors.push(failure)
      app.mount(host)
      app.config.errorHandler = undefined
      if (mountErrors.length > 0) throw mountErrors[0]
      await nextTick()
      await steps({
        root: host,
        server,
        async update(change) {
          change()
          await nextTick()
        },
        async keyboardFlush() {
          await nextTick()
          await nextTick()
        },
        async settle() {
          await vi.advanceTimersByTimeAsync(0)
          await nextTick()
        },
        async advance(milliseconds) {
          await vi.advanceTimersByTimeAsync(milliseconds)
          await nextTick()
        },
        unmount,
      })
    } finally {
      await unmount()
    }
  })
}

registerThemeMenuCases('Vue', { describe, it, expect }, withThemeMenu)
