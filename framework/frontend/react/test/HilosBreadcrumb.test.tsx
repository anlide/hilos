import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import type { HilosCrumb } from '@hilos/core'

import { HilosBreadcrumb } from '../src/HilosBreadcrumb.js'

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
      'Section',
    )
  })
})
