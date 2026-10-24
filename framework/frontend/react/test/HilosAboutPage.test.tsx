import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import type { HilosStaticPageNavigation } from '@hilos/core/base'

import { HilosAboutPage } from '../src/public/HilosAboutPage.js'

// The React peer of vue/src/public/HilosAboutPage.test.ts: the same cases by the
// same names, so a drift between the two view layers shows up as one of them
// failing rather than as a page nobody compared. The dialog portals to <body>,
// so its assertions query the document rather than the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function publicNavigation(
  page = 'fixture_public_page',
  project = 'first',
  label = 'Public pages',
): HilosStaticPageNavigation {
  return {
    page,
    tabs: [
      { page: 'fixture_public_page', label: 'Public page' },
      { page: 'fixture_history', label: 'Revision history' },
    ],
    params: { project },
    resolvePath: (target, params = {}) =>
      `/projects/${params.project}/${target}`,
    label,
  }
}

describe('HilosAboutPage', () => {
  it('renders its exact head and forwards optional navigation reactively', () => {
    const { rerender } = render(<HilosAboutPage />)

    expect(document.querySelectorAll('h1')).toHaveLength(1)
    const heading = byId('hilos-page-head-title')
    expect(heading?.textContent).toBe('About')
    const icon = byId('hilos-page-head-block')?.querySelector(
      'i.bi-info-circle',
    )
    expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
    expect(byId('hilos-page-head-block')?.querySelector('p')?.textContent).toBe(
      'What this project is, who makes it, and how it keeps going.',
    )
    expect(byId('hilos-breadcrumb')).toBeNull()
    expect(byId('hilos-page-tabs')).toBeNull()

    rerender(<HilosAboutPage navigation={publicNavigation()} />)
    const tabs = byId('hilos-page-tabs')
    expect(tabs?.getAttribute('aria-label')).toBe('Public pages')
    expect(
      [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent,
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Public page', '/projects/first/fixture_public_page'],
      ['Revision history', '/projects/first/fixture_history'],
    ])
    expect(
      tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
    ).toBe('hilos-page-tab-fixture_public_page')

    rerender(
      <HilosAboutPage
        navigation={publicNavigation(
          'fixture_history',
          'next',
          'More public pages',
        )}
      />,
    )
    expect(byId('hilos-page-head-title')).toBe(heading)
    expect(tabs?.getAttribute('aria-label')).toBe('More public pages')
    expect(
      tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
    ).toBe('hilos-page-tab-fixture_history')
    expect(
      tabs
        ?.querySelector('[data-id="hilos-page-tab-fixture_history"]')
        ?.getAttribute('href'),
    ).toBe('/projects/next/fixture_history')
  })

  it('draws the project’s prose and the support block, with no dialog open', () => {
    render(
      <HilosAboutPage>
        <p>This project is a demonstration.</p>
      </HilosAboutPage>,
    )

    expect(document.body.textContent).toContain(
      'This project is a demonstration.',
    )
    expect(byId('hilos-about-support')).not.toBeNull()
    // The heading is a real h2, never bold text standing in for one.
    expect(
      byId('hilos-about-support')?.querySelector('h2')?.textContent,
    ).toContain('no ads and no data selling')
    expect(byId('modal')).toBeNull()
  })

  it('opens the support dialog on the block’s one button', () => {
    render(<HilosAboutPage />)

    fireEvent.click(byId('hilos-about-support-open') as HTMLElement)

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-about-tier-medium')).not.toBeNull()
  })
})
