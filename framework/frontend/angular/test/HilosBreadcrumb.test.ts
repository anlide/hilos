// The Angular peer of react/test/HilosBreadcrumb.test.tsx: the trail's
// accessible name, the last crumb current and drawn as text, an addressless
// crumb kept as text, the slashes a screen reader skips, and the trail of one
// crumb the older pages still draw (HIL-1605).
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { createSignal } from '@hilos/core'
import type { HilosCrumb, HilosRouter } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosBreadcrumb } from '../src/HilosBreadcrumb.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const TRAIL: HilosCrumb[] = [
  { page: 'hilos', label: 'Dashboard', to: '/hilos' },
  { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
]

const ADDRESSLESS_TRAIL: HilosCrumb[] = [
  { page: 'hilos', label: 'Dashboard', to: '/hilos' },
  { page: 'unrouted', label: 'Section' },
  { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
]

/**
 * Mount the breadcrumb over a trail.
 *
 * @param crumbs The trail to draw.
 * @param label The accessible name to give, or undefined to give none.
 * @returns The rendered fixture.
 */
function mount(
  crumbs: HilosCrumb[],
  label?: string,
): ComponentFixture<HilosBreadcrumb> {
  const fixture = TestBed.createComponent(HilosBreadcrumb)
  fixture.componentRef.setInput('crumbs', crumbs)
  if (label !== undefined) {
    fixture.componentRef.setInput('label', label)
  }
  fixture.detectChanges()

  return fixture
}

/**
 * The rendered root of a fixture.
 *
 * @param fixture The mounted breadcrumb.
 * @returns Its host element.
 */
function root(fixture: ComponentFixture<HilosBreadcrumb>): HTMLElement {
  return fixture.nativeElement as HTMLElement
}

/**
 * The trail's items, in order.
 *
 * @param fixture The mounted breadcrumb.
 * @returns The list items of the trail.
 */
function items(fixture: ComponentFixture<HilosBreadcrumb>): HTMLLIElement[] {
  return [...root(fixture).querySelectorAll('li')]
}

describe('HilosBreadcrumb', () => {
  it('names the navigation by the label it is given', () => {
    const nav = root(mount(TRAIL, 'Path to this page')).querySelector('nav')

    expect(nav?.getAttribute('aria-label')).toBe('Path to this page')
    expect(nav?.dataset['id']).toBe('hilos-breadcrumb')
  })

  it('keeps its old name when no label is given', () => {
    const nav = root(mount(TRAIL)).querySelector('[data-id="hilos-breadcrumb"]')

    expect(nav?.getAttribute('aria-label')).toBe('breadcrumb')
  })

  it('marks the last crumb alone, whatever path the router stands at', () => {
    // The router already stands at the first crumb's address, as it does while
    // the departing page still shows: that link's own path match must not make
    // it a second current page.
    const router = {
      currentPath: createSignal('/hilos'),
      navigate: () => {},
    } as unknown as HilosRouter
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router }],
    })
    const fixture = mount(TRAIL)

    const current = root(fixture).querySelectorAll('[aria-current]')
    expect(current).toHaveLength(1)
    expect(current[0]).toBe(items(fixture)[1])
    expect(current[0]?.getAttribute('aria-current')).toBe('page')
    expect(
      root(fixture)
        .querySelector('[data-id="hilos-breadcrumb-hilos"]')
        ?.hasAttribute('aria-current'),
    ).toBe(false)
  })

  it('draws the last crumb as the current text even when it has an address', () => {
    const fixture = mount(TRAIL)

    expect(
      root(fixture).querySelector('[data-id="hilos-breadcrumb-hilos_billing"]'),
    ).toBeNull()
    expect(
      [...root(fixture).querySelectorAll('a')].map((a) => a.textContent),
    ).toEqual(['Dashboard'])
    expect(items(fixture)[1]?.textContent?.trim()).toBe('/ Billing')
    const current = root(fixture).querySelectorAll('[aria-current]')
    expect(current).toHaveLength(1)
    expect(current[0]).toBe(items(fixture)[1])
    expect(current[0]?.getAttribute('aria-current')).toBe('page')
  })

  it('draws an addressless crumb in the middle as text', () => {
    const fixture = mount(ADDRESSLESS_TRAIL)

    expect(items(fixture)).toHaveLength(3)
    expect(items(fixture)[1]?.querySelector('a')).toBeNull()
    expect(items(fixture)[1]?.textContent?.trim()).toBe('/ Section')
    expect(items(fixture)[1]?.hasAttribute('aria-current')).toBe(false)
    expect(
      root(fixture).querySelector('[data-id="hilos-breadcrumb-unrouted"]'),
    ).toBeNull()
    expect(
      root(fixture)
        .querySelector('[data-id="hilos-breadcrumb-hilos"]')
        ?.getAttribute('href'),
    ).toBe('/hilos')
  })

  it('puts a silent slash before every crumb but the first', () => {
    const fixture = mount(ADDRESSLESS_TRAIL)
    const [first, ...rest] = items(fixture)

    expect(first?.querySelector('span')).toBeNull()
    for (const item of rest) {
      const slashes = item.querySelectorAll(':scope > span')
      expect(slashes).toHaveLength(1)
      expect(item.firstElementChild).toBe(slashes[0])
      expect(slashes[0]?.textContent).toBe('/')
      expect(slashes[0]?.getAttribute('aria-hidden')).toBe('true')
      expect([...(slashes[0]?.classList ?? [])]).toEqual([
        'float-start',
        'pe-2',
        'text-body-secondary',
      ])
    }
    // The slashes stay out of the links, whose text the e2e specs read.
    expect(
      root(fixture).querySelector('[data-id="hilos-breadcrumb-hilos"]')
        ?.textContent,
    ).toBe('Dashboard')
  })

  it('draws the stock look with utilities and no generated slash', () => {
    const fixture = mount(ADDRESSLESS_TRAIL)

    expect([...(root(fixture).querySelector('ol')?.classList ?? [])]).toEqual([
      'breadcrumb',
      'small',
      'mb-2',
      'text-break',
    ])
    // No item carries breadcrumb-item: its ::before slash would be spoken.
    expect(items(fixture).map((item) => [...item.classList])).toEqual([
      [],
      ['ps-2'],
      ['ps-2', 'text-body-secondary'],
    ])
  })

  it('still draws a trail of one crumb as the current page', () => {
    const fixture = mount([{ page: 'hilos', label: 'Dashboard', to: '/hilos' }])

    expect(
      root(fixture).querySelector('[data-id="hilos-breadcrumb"]'),
    ).not.toBeNull()
    expect(items(fixture)).toHaveLength(1)
    expect(items(fixture)[0]?.getAttribute('aria-current')).toBe('page')
    expect(items(fixture)[0]?.textContent?.trim()).toBe('Dashboard')
    expect(root(fixture).querySelector('a')).toBeNull()
    expect(root(fixture).querySelector('span')).toBeNull()
  })

  it('draws nothing for an empty trail', () => {
    expect(root(mount([])).querySelector('nav')).toBeNull()
  })
})
