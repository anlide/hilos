// Angular mounting and lifecycle for the shared ThemeMenu scenarios.
import { ApplicationRef } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { HilosLayout, HilosThemeMenu } from '@hilos/angular/base'
import { describe, expect, it, vi } from 'vitest'

import { registerThemeMenuCases } from '../../test-support/themeMenuCases.js'
import {
  byId,
  shown,
  withThemeMenuState,
  type WithThemeMenu,
} from '../../test-support/themeMenuFixtures.js'

const withThemeMenu: WithThemeMenu = async (input, steps) => {
  await withThemeMenuState(input, async (server) => {
    let fixture: ComponentFixture<HilosThemeMenu | HilosLayout> | undefined
    let destroyed = false
    const update = (): void => {
      if (!destroyed) fixture?.detectChanges()
    }
    const unmount = async (): Promise<void> => {
      if (destroyed) return
      destroyed = true
      try {
        fixture?.destroy()
      } finally {
        ;(fixture?.nativeElement as HTMLElement | undefined)?.remove()
      }
    }
    try {
      fixture = TestBed.createComponent<HilosThemeMenu | HilosLayout>(
        input.shell ? HilosLayout : HilosThemeMenu,
      )
      if (input.shell) {
        fixture.componentRef.setInput('connection', server.connection)
        fixture.componentRef.setInput(
          'allowDisconnectedInteraction',
          input.allowDisconnectedInteraction ?? false,
        )
      }
      update()
      await steps({
        root: fixture.nativeElement as HTMLElement,
        server,
        async update(change) {
          change()
          update()
        },
        async keyboardFlush() {
          update()
        },
        async settle() {
          await vi.advanceTimersByTimeAsync(0)
          update()
          // A result after destruction must survive the next application render.
          if (destroyed) TestBed.inject(ApplicationRef).tick()
        },
        async advance(milliseconds) {
          await vi.advanceTimersByTimeAsync(milliseconds)
          update()
        },
        unmount,
      })
    } finally {
      try {
        await unmount()
      } finally {
        // Own fixtures even when initial input delivery or detection threw.
        TestBed.resetTestingModule()
      }
    }
  })
}

registerThemeMenuCases('Angular', { describe, it, expect }, withThemeMenu)

// Angular alone retains a component host in the header when it draws nothing.
describe('ThemeMenu Angular stable host', () => {
  it('L02 hides its host while switching is off and shows the same host on re-enable', async () => {
    await withThemeMenu(
      { settings: { switchingEnabled: false, defaultTheme: 'light' } },
      async (view) => {
        const host = view.root
        expect(host.isConnected).toBe(true)
        expect(shown(host)).toBe(false)
        await view.update(() =>
          view.server.snapshot(
            { switchingEnabled: true, defaultTheme: 'light' },
            'dark',
          ),
        )
        expect(view.root).toBe(host)
        expect(shown(host)).toBe(true)
        expect(byId(host, 'nav-theme').getAttribute('data-position')).toBe(
          'dark',
        )
      },
    )
  })
})
