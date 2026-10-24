import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type { HilosStaticPageNavigation } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { HilosAboutPage } from '../src/public/HilosAboutPage.js'

/** Mount the page without project navigation. */
function mountPage(): ComponentFixture<HilosAboutPage> {
  const fixture = TestBed.createComponent(HilosAboutPage)
  fixture.detectChanges()

  return fixture
}

/**
 * Find a node of the mounted page by its stable test id.
 *
 * @param fixture The mounted page to search.
 * @param id The `data-id` the page renders on the node.
 * @returns The matching node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosAboutPage>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

/**
 * Navigation that resolves sibling pages against one project route parameter.
 *
 * @param page The page key marked current.
 * @param project The project route parameter.
 * @param label The accessible name of the tabs row.
 * @returns A static-page navigation fixture.
 */
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
    const fixture = mountPage()

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll('h1'),
    ).toHaveLength(1)
    const heading = byId(fixture, 'hilos-page-head-title')
    expect(heading?.textContent?.trim()).toBe('About')
    const icon = byId(fixture, 'hilos-page-head-block')?.querySelector(
      'i.bi-info-circle',
    )
    expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
    expect(
      byId(fixture, 'hilos-page-head-block')
        ?.querySelector('p')
        ?.textContent?.trim(),
    ).toBe('What this project is, who makes it, and how it keeps going.')
    expect(byId(fixture, 'hilos-breadcrumb')).toBeNull()
    expect(byId(fixture, 'hilos-page-tabs')).toBeNull()

    fixture.componentRef.setInput('navigation', publicNavigation())
    fixture.detectChanges()
    const tabs = byId(fixture, 'hilos-page-tabs')
    expect(tabs?.getAttribute('aria-label')).toBe('Public pages')
    expect(
      [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent?.trim(),
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Public page', '/projects/first/fixture_public_page'],
      ['Revision history', '/projects/first/fixture_history'],
    ])
    expect(
      tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
    ).toBe('hilos-page-tab-fixture_public_page')

    fixture.componentRef.setInput(
      'navigation',
      publicNavigation('fixture_history', 'next', 'More public pages'),
    )
    fixture.detectChanges()
    expect(byId(fixture, 'hilos-page-head-title')).toBe(heading)
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
})
