// Angular inputs, providers and lifecycle for the shared error/auth union.
import { Component, type Type } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import { AUTH_SURFACE_HEADING_ID, type PageError } from '@hilos/core/base'

import {
  registerErrorPageCases,
  type ErrorPageView,
} from '../../test-support/errorPageCases.js'
import { DELETED } from '../../test-support/errorPageFixtures.js'
import {
  registerPageErrorViewCases,
  type WithPageErrorView,
} from '../../test-support/pageErrorViewCases.js'
import { ErrorPage } from '../src/ErrorPage.js'
import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Angular's imperative homeLink API is not part of React's props-only API. */
interface ExposedErrorPageView extends ErrorPageView {
  homeLink(): HTMLElement
}

/** TestBed owns a created fixture even if setting inputs or detection fails. */
async function withErrorPage(
  error: PageError,
  steps: (view: ExposedErrorPageView) => Promise<void>,
): Promise<void> {
  const previousFocus = document.activeElement
  try {
    const fixture = TestBed.createComponent(ErrorPage)
    fixture.componentRef.setInput('error', error)
    fixture.detectChanges()
    await fixture.whenStable()
    await steps({
      root: fixture.nativeElement as HTMLElement,
      async update(value) {
        fixture.componentRef.setInput('error', value)
        fixture.detectChanges()
        await fixture.whenStable()
      },
      homeLink: () => fixture.componentInstance.homeLink(),
    })
  } finally {
    try {
      TestBed.resetTestingModule()
    } finally {
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}

@Component({
  selector: 'test-error-user-page',
  template: '<div data-id="user-page"></div>',
})
class UserPage {}

/** Mount the native outlet; only the project-supplied auth body is a fixture. */
const withPageErrorView: WithPageErrorView = async (input, steps) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  let authSurface: Type<unknown> | undefined
  if (input.authSurface) {
    const heading = input.authSurface.heading
    @Component({
      selector: 'test-error-auth-surface',
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
    fixture.componentRef.setInput('pages', { user: UserPage })
    fixture.componentRef.setInput('authSurface', authSurface)
    fixture.componentRef.setInput('authGate', input.authGate)
    const flush = async (change?: () => void): Promise<void> => {
      change?.()
      fixture.detectChanges()
      await fixture.whenStable()
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

registerErrorPageCases('Angular', { describe, it, expect }, withErrorPage)
registerPageErrorViewCases(
  'Angular',
  { describe, it, expect },
  withPageErrorView,
)

// This calls Angular's actual method; shared cases already hold the link behavior.
describe('ErrorPage Angular exposed API', () => {
  it('hands the anchor of its way home to the shell that draws it', async () => {
    await withErrorPage(DELETED, async (view) => {
      const home = view.root.querySelector('[data-id="page-error-home"]')
      expect(home).not.toBeNull()
      expect(view.homeLink()).toBe(home)
    })
  })
})
