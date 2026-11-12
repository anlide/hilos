// React mounting and lifecycle for the shared ThemeMenu scenarios.
import { HilosLayout, HilosThemeMenu } from '@hilos/react/base'
import { act, cleanup, render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { registerThemeMenuCases } from '../../test-support/themeMenuCases.js'
import {
  byId,
  key,
  withThemeMenuState,
  type WithThemeMenu,
} from '../../test-support/themeMenuFixtures.js'

const withThemeMenu: WithThemeMenu = async (input, steps) => {
  await withThemeMenuState(input, async (server) => {
    const host = document.body.appendChild(document.createElement('div'))
    let mounted: { unmount(): void } | undefined
    let destroyed = false
    const unmount = async (): Promise<void> => {
      if (destroyed) return
      destroyed = true
      try {
        mounted?.unmount()
      } finally {
        // Also owns roots registered by a render that failed before returning.
        cleanup()
        host.remove()
      }
    }
    try {
      mounted = render(
        input.shell ? (
          <HilosLayout
            connection={server.connection}
            allowDisconnectedInteraction={input.allowDisconnectedInteraction}
          >
            <p data-id="page-body">Page</p>
          </HilosLayout>
        ) : (
          <HilosThemeMenu />
        ),
        { container: host },
      )
      await steps({
        root: host,
        server,
        async update(change) {
          act(change)
        },
        async keyboardFlush() {
          await act(async () => {
            vi.advanceTimersToNextFrame()
          })
        },
        async settle() {
          await act(async () => {
            await vi.advanceTimersByTimeAsync(0)
          })
        },
        async advance(milliseconds) {
          await act(async () => {
            await vi.advanceTimersByTimeAsync(milliseconds)
          })
        },
        unmount,
      })
    } finally {
      await unmount()
    }
  })
}

registerThemeMenuCases('React', { describe, it, expect }, withThemeMenu)

// Only React schedules keyboard focus through the cancellable RAF API.
describe('ThemeMenu React scheduling API', () => {
  it('L01 cancels the pending keyboard animation frame on unmount', async () => {
    await withThemeMenu(
      {
        pick: null,
        settings: { switchingEnabled: true, defaultTheme: 'system' },
      },
      async (view) => {
        const cancel = vi.spyOn(globalThis, 'cancelAnimationFrame')
        await view.update(() => key(byId(view.root, 'nav-theme'), 'ArrowDown'))
        await view.unmount()
        expect(cancel).toHaveBeenCalled()
      },
    )
  })
})
