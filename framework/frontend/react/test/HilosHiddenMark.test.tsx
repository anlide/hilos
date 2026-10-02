import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'

import { HilosHiddenMark } from '../src/HilosHiddenMark.js'

afterEach(() => {
  cleanup()
})

describe('HilosHiddenMark', () => {
  it('draws a soft grey pill with a decorative struck-out eye and the word', () => {
    const { container } = render(<HilosHiddenMark />)
    const mark = container.querySelector('[data-id="hilos-hidden"]')!

    expect(mark.classList.contains('badge')).toBe(true)
    expect(mark.classList.contains('rounded-pill')).toBe(true)
    expect(mark.classList.contains('bg-body-secondary')).toBe(true)
    expect(mark.classList.contains('text-body-secondary')).toBe(true)
    expect(mark.classList.contains('fw-medium')).toBe(true)
    const icon = mark.querySelector('i')!
    expect(icon.classList.contains('bi-eye-slash')).toBe(true)
    expect(icon.getAttribute('aria-hidden')).toBe('true')
    expect(mark.textContent).toBe('Hidden')
  })
})
