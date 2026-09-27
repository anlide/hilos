import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'

import { HilosAvatar } from '../src/HilosAvatar.js'

afterEach(() => {
  cleanup()
})

describe('HilosAvatar', () => {
  it('draws initials in a decorative circle of the default size', () => {
    const { container } = render(<HilosAvatar name="Мария Ковалёва" />)
    const circle = container.querySelector('[data-id="hilos-avatar"]')!

    expect(circle.textContent).toBe('МК')
    expect(circle.getAttribute('aria-hidden')).toBe('true')
    expect(circle.hasAttribute('role')).toBe(false)
    expect(circle.hasAttribute('tabindex')).toBe(false)
    expect(circle.hasAttribute('title')).toBe(false)
    expect(circle.classList.contains('hilos-avatar-sm')).toBe(true)
  })

  it.each(['', '🤖'])('draws the person icon for %j', (name) => {
    const { container } = render(<HilosAvatar name={name} />)

    expect(container.querySelector('i.bi-person')).not.toBeNull()
    expect(container.textContent).toBe('')
  })

  it.each(['sm', 'md', 'lg'] as const)('draws size %s', (size) => {
    const { container } = render(<HilosAvatar name="Alexander" size={size} />)

    expect(container.querySelector(`.hilos-avatar-${size}`)).not.toBeNull()
  })

  it('updates the initials and fallback when the name changes', () => {
    const { container, rerender } = render(
      <HilosAvatar name="Alexander Baranov" />,
    )

    expect(container.textContent).toBe('AB')
    rerender(<HilosAvatar name="Мария Ковалёва" />)
    expect(container.textContent).toBe('МК')
    rerender(<HilosAvatar name="🤖" />)
    expect(container.textContent).toBe('')
    expect(container.querySelector('i.bi-person')).not.toBeNull()
    rerender(<HilosAvatar name="Alexander" />)
    expect(container.textContent).toBe('A')
    expect(container.querySelector('i.bi-person')).toBeNull()
  })
})
