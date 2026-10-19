import { TestBed } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
import type { PageSubscriptionError } from '@hilos/core'

import { ErrorPage } from '../src/ErrorPage.js'

const FORBIDDEN: PageSubscriptionError = {
  page: 'user',
  httpCode: 403,
  errorCode: 'forbidden',
  title: 'Server <b>title</b>',
  message: 'Server <em>description</em> & details.',
}

describe('ErrorPage', () => {
  afterEach(() => TestBed.resetTestingModule())

  it('renders arbitrary server text literally for a known status', () => {
    const fixture = TestBed.createComponent(ErrorPage)
    fixture.componentRef.setInput('error', FORBIDDEN)
    fixture.detectChanges()

    const element = fixture.nativeElement as HTMLElement
    const surface = element.querySelector('[data-id="page-error"]')
    expect(surface?.getAttribute('data-error-code')).toBe('403')
    expect(surface?.querySelector('h1')?.textContent?.trim()).toBe('403')
    expect(surface?.querySelector('.lead')?.textContent).toBe(
      'Server <b>title</b>',
    )
    expect(surface?.querySelector('p.text-body-secondary')?.textContent).toBe(
      'Server <em>description</em> & details.',
    )
    expect(surface?.querySelector('b, em')).toBeNull()
    expect(
      surface
        ?.querySelector('[data-id="page-error-home"]')
        ?.getAttribute('href'),
    ).toBe('/')
    expect(
      surface
        ?.querySelector('[data-id="page-error-home"]')
        ?.textContent?.trim(),
    ).toBe('Back to home')
  })
})
