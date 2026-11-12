// Angular's required page input is installed before the first detection.
import { Component, type Type } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { AUTH_SURFACE_HEADING_ID } from '@hilos/core/base'

import type { WithHilosViewOutlet } from '../../test-support/hilosViewFixtures.js'
import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

@Component({
  selector: 'test-outlet-main',
  template: '<div data-id="main">Leaving page</div>',
})
class MainPage {}

@Component({
  selector: 'test-outlet-known',
  template: '<div data-id="known-page">Known</div>',
})
class KnownPage {}

@Component({
  selector: 'test-outlet-skeleton',
  template: '<div data-id="main-skeleton"></div>',
})
class MainSkeleton {}

export const withHilosViewOutlet: WithHilosViewOutlet = async (
  input,
  steps,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  let authSurface: Type<unknown> | undefined
  if (input.authSurface) {
    const heading = input.authSurface.heading
    @Component({
      selector: 'test-outlet-auth',
      template: `
        <div data-id="auth-surface">
          @if (heading !== undefined) {
            <h2 [id]="headingId">{{ heading }}</h2>
          }
        </div>
      `,
    })
    class AuthSurface {
      protected readonly heading = heading
      protected readonly headingId = AUTH_SURFACE_HEADING_ID
    }
    authSurface = AuthSurface
  }
  try {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
    })
    const fixture = TestBed.createComponent(HilosView)
    fixture.componentRef.setInput('pages', { main: MainPage, known: KnownPage })
    if (input.withSkeletons)
      fixture.componentRef.setInput('pageSkeletons', { main: MainSkeleton })
    fixture.componentRef.setInput('authSurface', authSurface)
    fixture.componentRef.setInput('authGate', input.authGate)
    const flush = async (change?: () => void) => {
      change?.()
      fixture.detectChanges()
      await Promise.resolve()
      fixture.detectChanges()
    }
    await flush()
    await steps({ root: fixture.nativeElement as HTMLElement, flush })
  } finally {
    try {
      TestBed.resetTestingModule()
    } finally {
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
