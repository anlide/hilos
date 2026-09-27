import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { HilosAvatar } from '../src/HilosAvatar.js'

/**
 * Mount the avatar with the inputs a case sets.
 *
 * @param name The person's name.
 * @param size The optional size override.
 * @returns The mounted fixture.
 */
function mount(
  name: string,
  size?: 'sm' | 'md' | 'lg',
): ComponentFixture<HilosAvatar> {
  const fixture = TestBed.createComponent(HilosAvatar)
  fixture.componentRef.setInput('name', name)
  if (size !== undefined) fixture.componentRef.setInput('size', size)
  fixture.detectChanges()

  return fixture
}

function circle(fixture: ComponentFixture<HilosAvatar>): HTMLElement {
  return (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="hilos-avatar"]',
  )!
}

describe('HilosAvatar', () => {
  it('draws initials in a decorative circle of the default size', () => {
    const drawn = circle(mount('Мария Ковалёва'))

    expect(drawn.textContent?.trim()).toBe('МК')
    expect(drawn.getAttribute('aria-hidden')).toBe('true')
    expect(drawn.hasAttribute('role')).toBe(false)
    expect(drawn.hasAttribute('tabindex')).toBe(false)
    expect(drawn.hasAttribute('title')).toBe(false)
    expect(drawn.classList.contains('hilos-avatar-sm')).toBe(true)
  })

  it.each(['', '🤖'])('draws the person icon for %j', (name) => {
    const drawn = circle(mount(name))

    expect(drawn.querySelector('i.bi-person')).not.toBeNull()
    expect(drawn.textContent?.trim()).toBe('')
  })

  it.each(['sm', 'md', 'lg'] as const)('draws size %s', (size) => {
    expect(
      circle(mount('Alexander', size)).classList.contains(
        `hilos-avatar-${size}`,
      ),
    ).toBe(true)
  })

  it('updates the initials and fallback when the name changes', () => {
    const fixture = mount('Alexander Baranov')

    expect(circle(fixture).textContent?.trim()).toBe('AB')
    fixture.componentRef.setInput('name', 'Мария Ковалёва')
    fixture.detectChanges()
    expect(circle(fixture).textContent?.trim()).toBe('МК')
    fixture.componentRef.setInput('name', '🤖')
    fixture.detectChanges()
    expect(circle(fixture).textContent?.trim()).toBe('')
    expect(circle(fixture).querySelector('i.bi-person')).not.toBeNull()
    fixture.componentRef.setInput('name', 'Alexander')
    fixture.detectChanges()
    expect(circle(fixture).textContent?.trim()).toBe('A')
    expect(circle(fixture).querySelector('i.bi-person')).toBeNull()
  })
})
