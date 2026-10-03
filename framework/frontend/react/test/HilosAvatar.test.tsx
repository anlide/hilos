import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

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

  it('draws no mark by default', () => {
    const { container } = render(<HilosAvatar name="Ada" />)
    const circle = container.querySelector('[data-id="hilos-avatar"]')!

    expect(container.querySelector('[data-id="avatar-mark"]')).toBeNull()
    expect(circle.classList.contains('border')).toBe(false)
  })

  it.each([
    [{ tone: 'warning', icon: 'bi-trash' }],
    [{ tone: 'danger', icon: 'bi-people-fill' }],
    [{ tone: 'info', icon: 'bi-people-fill' }],
  ] as const)(
    'rings the circle and puts the icon in its corner for %j (HIL-945)',
    (mark) => {
      const { container, rerender } = render(
        <HilosAvatar name="Ada" mark={mark} />,
      )
      const circle = container.querySelector('[data-id="hilos-avatar"]')!

      for (const name of [
        'position-relative',
        'border',
        'border-2',
        `border-${mark.tone}`,
      ]) {
        expect(circle.classList.contains(name)).toBe(true)
      }
      const icon = container.querySelector('[data-id="avatar-mark"]')!
      for (const name of [
        'bi',
        mark.icon,
        'position-absolute',
        'hilos-avatar-mark',
        `text-${mark.tone}-emphasis`,
      ]) {
        expect(icon.classList.contains(name)).toBe(true)
      }
      expect(circle.textContent).toBe('A')
      expect(circle.getAttribute('aria-hidden')).toBe('true')

      rerender(<HilosAvatar name="Ada" mark={null} />)
      expect(container.querySelector('[data-id="avatar-mark"]')).toBeNull()
      expect(circle.classList.contains(`border-${mark.tone}`)).toBe(false)
    },
  )

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

  it('shows a decorative photo, falls back on error, and tries the next photo', () => {
    const { container, rerender } = render(
      <HilosAvatar name="Ada Baranov" photo="/a.webp" />,
    )
    const image = container.querySelector('[data-id="hilos-avatar-photo"]')!
    expect(image.getAttribute('alt')).toBe('')
    expect(image.getAttribute('src')).toBe('/a.webp')
    expect(container.textContent).toBe('')

    fireEvent.error(image)
    expect(container.querySelector('img')).toBeNull()
    expect(container.textContent).toBe('AB')

    rerender(<HilosAvatar name="Ada Baranov" photo="/b.webp" />)
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/b.webp')
  })
})
