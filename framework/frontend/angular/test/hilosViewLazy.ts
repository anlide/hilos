// Angular fixtures receive all required inputs before their first detection.
import { Component, type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { vi } from 'vitest'
import { hilosAdminViews } from '@hilos/angular/admin-views'

import type { WithHilosViewLazy } from '../../test-support/hilosViewLazyFixtures.js'
import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

@Component({
  selector: 'test-lazy-main',
  template: '<div data-id="main">Leaving page</div>',
})
class MainPage {}
@Component({
  selector: 'test-lazy-known',
  template: '<div data-id="known-page">Known</div>',
})
class KnownPage {}
@Component({
  selector: 'test-lazy-skeleton',
  template: '<div data-id="main-skeleton"></div>',
})
class MainSkeleton {}

export const withHilosViewLazy: WithHilosViewLazy<Type<unknown>> = async (
  steps,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const fixtures: ComponentFixture<HilosView>[] = []
  try {
    await steps({
      main: MainPage,
      known: KnownPage,
      skeleton: MainSkeleton,
      tracked(id) {
        const mounted = vi.fn()
        @Component({
          selector: 'test-lazy-tracked',
          template: `<div data-id="${id}">${id}</div>`,
        })
        class TrackedPage {
          constructor() {
            mounted()
          }
        }
        return { component: TrackedPage, mounted }
      },
      adminViews: hilosAdminViews,
      async mount(input) {
        if (fixtures.length === 0) {
          TestBed.configureTestingModule({
            providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
          })
        }
        const fixture = TestBed.createComponent(HilosView)
        fixtures.push(fixture)
        for (const [key, value] of Object.entries(input)) {
          if (key !== 'router') fixture.componentRef.setInput(key, value)
        }
        let disposed = false
        const flush = async (change?: () => void) => {
          change?.()
          await Promise.resolve()
          if (!disposed) fixture.detectChanges()
          await Promise.resolve()
          if (!disposed) fixture.detectChanges()
        }
        await flush()
        return {
          root: fixture.nativeElement as HTMLElement,
          flush,
          async setPages(pages) {
            fixture.componentRef.setInput('pages', pages)
            await flush()
          },
          async unmount() {
            if (disposed) return
            disposed = true
            fixture.destroy()
          },
        }
      },
    })
  } finally {
    try {
      for (const fixture of fixtures.reverse()) fixture.destroy()
      TestBed.resetTestingModule()
    } finally {
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
