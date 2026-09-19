import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'

import { HilosSkeleton } from '../src/HilosSkeleton.js'

afterEach(() => {
  cleanup()
})

function bars(container: HTMLElement): Element[] {
  return Array.from(container.querySelectorAll('.placeholder'))
}

describe('HilosSkeleton', () => {
  it('draws the three default bars, 9, 6 and 10 columns wide', () => {
    const { container } = render(<HilosSkeleton />)
    const drawn = bars(container)

    expect(drawn).toHaveLength(3)
    expect(drawn[0].classList.contains('col-9')).toBe(true)
    expect(drawn[1].classList.contains('col-6')).toBe(true)
    expect(drawn[2].classList.contains('col-10')).toBe(true)
    expect(drawn[0].classList.contains('mb-2')).toBe(true)
    expect(drawn[1].classList.contains('mb-2')).toBe(true)
    expect(drawn[2].classList.contains('mb-2')).toBe(false)
  })

  it('draws the bars it is given', () => {
    const { container } = render(<HilosSkeleton lines={[4, 12]} />)
    const drawn = bars(container)

    expect(drawn).toHaveLength(2)
    expect(drawn[0].classList.contains('col-4')).toBe(true)
    expect(drawn[1].classList.contains('col-12')).toBe(true)
  })

  it('hides every bar from a screen reader', () => {
    const { container } = render(<HilosSkeleton />)

    for (const bar of bars(container)) {
      expect(bar.getAttribute('aria-hidden')).toBe('true')
    }
  })

  it('stays silent without a label', () => {
    const { container } = render(<HilosSkeleton />)

    expect(container.querySelectorAll('[role="status"]')).toHaveLength(0)
  })

  it('announces a label once for the whole frame', () => {
    const { container } = render(<HilosSkeleton label="Loading…" />)
    const status = container.querySelectorAll('[role="status"]')

    expect(status).toHaveLength(1)
    expect(status[0].textContent).toBe('Loading…')
  })
})
