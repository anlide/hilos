import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type { HilosAvatarMark } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosAvatar } from '../src/HilosAvatar.js'

/**
 * Mount the avatar with the inputs a case sets.
 *
 * @param name The person's name.
 * @param size The optional size override.
 * @param mark The optional standing mark.
 * @returns The mounted fixture.
 */
function mount(
  name: string,
  size?: 'sm' | 'md' | 'lg',
  mark?: HilosAvatarMark | null,
): ComponentFixture<HilosAvatar> {
  const fixture = TestBed.createComponent(HilosAvatar)
  fixture.componentRef.setInput('name', name)
  if (size !== undefined) fixture.componentRef.setInput('size', size)
  if (mark !== undefined) fixture.componentRef.setInput('mark', mark)
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

  it('draws no mark by default', () => {
    const drawn = circle(mount('Ada'))

    expect(drawn.querySelector('[data-id="avatar-mark"]')).toBeNull()
    expect(drawn.classList.contains('border')).toBe(false)
  })

  it.each([
    [{ tone: 'warning', icon: 'bi-trash' }],
    [{ tone: 'danger', icon: 'bi-people-fill' }],
    [{ tone: 'info', icon: 'bi-people-fill' }],
  ] as const)(
    'rings the circle and puts the icon in its corner for %j (HIL-945)',
    (mark) => {
      const fixture = mount('Ada', undefined, mark)
      const drawn = circle(fixture)

      for (const name of [
        'hilos-avatar-sm',
        'position-relative',
        'border',
        'border-2',
        `border-${mark.tone}`,
      ]) {
        expect(drawn.classList.contains(name)).toBe(true)
      }
      const icon = drawn.querySelector('[data-id="avatar-mark"]')!
      for (const name of [
        'bi',
        mark.icon,
        'position-absolute',
        'hilos-avatar-mark',
        `text-${mark.tone}-emphasis`,
      ]) {
        expect(icon.classList.contains(name)).toBe(true)
      }
      expect(drawn.textContent?.trim()).toBe('A')
      expect(drawn.getAttribute('aria-hidden')).toBe('true')

      fixture.componentRef.setInput('mark', null)
      fixture.detectChanges()
      expect(
        circle(fixture).querySelector('[data-id="avatar-mark"]'),
      ).toBeNull()
      expect(circle(fixture).classList.contains(`border-${mark.tone}`)).toBe(
        false,
      )
    },
  )

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
