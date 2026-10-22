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

// The description is the paragraph right after the title: once the transition
// line is there, p.text-body-secondary names two paragraphs.
const DESCRIPTION = '.lead + p'

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
    expect(surface?.querySelector(DESCRIPTION)?.textContent).toBe(
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

  it('renders a route miss that carries no page key', () => {
    const fixture = TestBed.createComponent(ErrorPage)
    fixture.componentRef.setInput('error', {
      httpCode: 404,
      errorCode: 'route_not_found',
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
    })
    fixture.detectChanges()

    const element = fixture.nativeElement as HTMLElement
    const surface = element.querySelector('[data-id="page-error"]')
    expect(element.querySelectorAll('h1')).toHaveLength(1)
    expect(surface?.querySelector('h1')?.textContent?.trim()).toBe('404')
    expect(surface?.querySelector('.lead')?.textContent).toBe('Page Not Found')
    expect(surface?.querySelector(DESCRIPTION)?.textContent).toBe(
      'The page you are looking for does not exist.',
    )
    expect(
      surface
        ?.querySelector('[data-id="page-error-home"]')
        ?.getAttribute('href'),
    ).toBe('/')
  })
})

const NOT_FOUND: PageSubscriptionError = {
  page: 'country',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

/** The live 404 of a page whose resource was deleted while it stood open. */
const DELETED: PageSubscriptionError = {
  ...NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

describe('ErrorPage transition line', () => {
  const LINE = '[data-id="page-error-transition-message"]'

  afterEach(() => TestBed.resetTestingModule())

  function render(error: PageSubscriptionError) {
    const fixture = TestBed.createComponent(ErrorPage)
    fixture.componentRef.setInput('error', error)
    fixture.detectChanges()
    const surface = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="page-error"]',
    )!

    return { fixture, surface }
  }

  it('draws the line under the description and before the way home', () => {
    const { surface } = render(DELETED)

    const description = surface.querySelector(DESCRIPTION)!
    const line = surface.querySelector(LINE)!
    const home = surface.querySelector('[data-id="page-error-home"]')!
    expect(description.textContent).toBe(NOT_FOUND.message)
    expect([...description.classList]).toEqual(['text-body-secondary', 'mb-1'])
    expect(line.tagName).toBe('P')
    expect([...line.classList]).toEqual(['small', 'text-body-secondary'])
    expect(line.textContent?.trim()).toBe('It was deleted just now.')
    expect(description.nextElementSibling).toBe(line)
    expect(line.nextElementSibling).toBe(home)
    expect(surface.querySelectorAll('h1')).toHaveLength(1)
    expect(
      surface.querySelectorAll('[data-id="page-error-home"]'),
    ).toHaveLength(1)
  })

  it('shows any server line literally, never as markup', () => {
    const { surface } = render({
      ...DELETED,
      transitionMessage: 'Gone <b>now</b> & for good',
    })

    const line = surface.querySelector(LINE)!
    expect(line.textContent?.trim()).toBe('Gone <b>now</b> & for good')
    expect(line.querySelector('b')).toBeNull()
    expect(line.innerHTML.trim()).toBe(
      'Gone &lt;b&gt;now&lt;/b&gt; &amp; for good',
    )
  })

  it('draws neither the line nor its margin for a refusal without the key', () => {
    const { surface } = render(NOT_FOUND)

    expect(surface.querySelector(LINE)).toBeNull()
    expect([...surface.querySelector(DESCRIPTION)!.classList]).toEqual([
      'text-body-secondary',
    ])
    expect(surface.querySelectorAll('p')).toHaveLength(2)
  })

  it('takes the line away when the next refusal carries no key', () => {
    const { fixture, surface } = render(DELETED)

    fixture.componentRef.setInput('error', NOT_FOUND)
    fixture.detectChanges()
    expect(surface.querySelector(LINE)).toBeNull()
    expect([...surface.querySelector(DESCRIPTION)!.classList]).toEqual([
      'text-body-secondary',
    ])

    fixture.componentRef.setInput('error', {
      ...FORBIDDEN,
      transitionMessage: 'Moved.',
    })
    fixture.detectChanges()
    expect(surface.querySelector(LINE)?.textContent?.trim()).toBe('Moved.')
    fixture.componentRef.setInput('error', FORBIDDEN)
    fixture.detectChanges()
    expect(surface.querySelector(LINE)).toBeNull()
    expect(surface.querySelectorAll('h1')).toHaveLength(1)
    expect(
      surface.querySelectorAll('[data-id="page-error-home"]'),
    ).toHaveLength(1)
  })

  it('hands the anchor of its way home to the shell that draws it', () => {
    const { fixture, surface } = render(DELETED)

    expect(fixture.componentInstance.homeLink()).toBe(
      surface.querySelector('[data-id="page-error-home"]'),
    )
  })
})
