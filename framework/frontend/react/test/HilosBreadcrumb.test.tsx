import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { createSignal } from '@hilos/core'
import type { HilosCrumb, HilosRouter } from '@hilos/core'

import { HilosBreadcrumb } from '../src/HilosBreadcrumb.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const TRAIL: HilosCrumb[] = [
  { page: 'hilos', label: 'Dashboard', to: '/hilos' },
  { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
]

describe('HilosBreadcrumb', () => {
  afterEach(cleanup)

  it('renders nothing for an empty trail', () => {
    const { container } = render(<HilosBreadcrumb crumbs={[]} />)
    expect(container.querySelector('[data-id="hilos-breadcrumb"]')).toBeNull()
  })

  it('links every crumb but the last, which is the active page', () => {
    render(<HilosBreadcrumb crumbs={TRAIL} />)

    const link = screen.getByRole('link', { name: 'Dashboard' })
    expect(link.getAttribute('href')).toBe('/hilos')

    const active = screen.getByText('Billing')
    expect(active.getAttribute('aria-current')).toBe('page')
    expect(screen.queryByRole('link', { name: 'Billing' })).toBeNull()
  })

  it('draws a crumb with no address as text, keeping the chain whole', () => {
    // A link that goes nowhere is worse than no link, but a missing link is
    // worse still: the ancestry would renumber and name the wrong parent.
    const { container } = render(
      <HilosBreadcrumb
        crumbs={[
          { page: 'hilos', label: 'Dashboard', to: '/hilos' },
          { page: 'unrouted', label: 'Section' },
          { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
        ]}
      />,
    )

    expect(container.querySelectorAll('a')).toHaveLength(1)
    expect(container.querySelectorAll('.breadcrumb-item')).toHaveLength(3)
    expect(container.querySelectorAll('.breadcrumb-item')[1].textContent).toBe(
      '/Section',
    )
    expect(container.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(
      container
        .querySelectorAll('.breadcrumb-item')[2]
        .getAttribute('aria-current'),
    ).toBe('page')
  })

  it('marks the last crumb alone, whatever path the router stands at', () => {
    // The router already stands at the first crumb's address: its link's own
    // path match must not make it a second current page.
    const router = {
      currentPath: createSignal('/hilos'),
      navigate: () => {},
    } as unknown as HilosRouter
    const { container } = render(
      <HilosRouterContext.Provider value={router}>
        <HilosBreadcrumb crumbs={TRAIL} />
      </HilosRouterContext.Provider>,
    )

    const current = container.querySelectorAll('[aria-current]')
    expect(current).toHaveLength(1)
    expect(current[0].tagName).toBe('LI')
    expect(current[0].getAttribute('aria-current')).toBe('page')
    expect(current[0].textContent).toBe('/Billing')
    expect(
      screen
        .getByRole('link', { name: 'Dashboard' })
        .hasAttribute('aria-current'),
    ).toBe(false)
  })

  it('draws the last crumb as text even when it has an address', () => {
    const { container } = render(<HilosBreadcrumb crumbs={TRAIL} />)

    expect(
      container.querySelector('[data-id="hilos-breadcrumb-hilos_billing"]'),
    ).toBeNull()
    expect(
      [...container.querySelectorAll('a')].map((a) => a.textContent),
    ).toEqual(['Dashboard'])
  })

  it('puts a silent slash before every crumb but the first', () => {
    const { container } = render(
      <HilosBreadcrumb
        crumbs={[
          { page: 'hilos', label: 'Dashboard', to: '/hilos' },
          { page: 'unrouted', label: 'Section' },
          { page: 'hilos_billing', label: 'Billing', to: '/hilos/billing' },
        ]}
      />,
    )

    const items = [...container.querySelectorAll('.breadcrumb-item')]
    expect(items[0].querySelector('span')).toBeNull()
    for (const item of items.slice(1)) {
      const slashes = item.querySelectorAll(':scope > span')
      expect(slashes).toHaveLength(1)
      expect(item.firstElementChild).toBe(slashes[0])
      expect(slashes[0].textContent).toBe('/')
      expect(slashes[0].getAttribute('aria-hidden')).toBe('true')
      expect([...slashes[0].classList]).toEqual([
        'float-start',
        'pe-2',
        'text-body-secondary',
      ])
    }
    // The slashes stay out of the links' names.
    expect(screen.getByRole('link', { name: 'Dashboard' }).textContent).toBe(
      'Dashboard',
    )
    // The scoped class switches the stock ::before separator off.
    expect(
      container
        .querySelector('[data-id="hilos-breadcrumb"]')
        ?.classList.contains('hilos-breadcrumb'),
    ).toBe(true)
  })

  it('names the navigation by the label it is given', () => {
    render(<HilosBreadcrumb crumbs={TRAIL} label="Path to this page" />)

    expect(
      screen.getByRole('navigation', { name: 'Path to this page' }).dataset.id,
    ).toBe('hilos-breadcrumb')
  })

  it('keeps its old name when no label is given', () => {
    const { container } = render(<HilosBreadcrumb crumbs={TRAIL} />)

    expect(
      container
        .querySelector('[data-id="hilos-breadcrumb"]')
        ?.getAttribute('aria-label'),
    ).toBe('breadcrumb')
  })

  it('adds the classes it is given beside its own', () => {
    const { container } = render(
      <HilosBreadcrumb crumbs={TRAIL} className="text-break" />,
    )

    expect([
      ...(container.querySelector('[data-id="hilos-breadcrumb"]')?.classList ??
        []),
    ]).toEqual(['hilos-breadcrumb', 'text-break'])
  })
})
