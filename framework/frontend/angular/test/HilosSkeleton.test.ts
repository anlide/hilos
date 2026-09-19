import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { HilosSkeleton } from '../src/HilosSkeleton.js'

/**
 * Mount the skeleton with the inputs a case sets.
 *
 * @param inputs The inputs to set before the first change detection.
 * @returns The mounted fixture.
 */
function mount(
  inputs: { lines?: number[]; label?: string } = {},
): ComponentFixture<HilosSkeleton> {
  const fixture = TestBed.createComponent(HilosSkeleton)
  for (const [name, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(name, value)
  }
  fixture.detectChanges()

  return fixture
}

function bars(fixture: ComponentFixture<HilosSkeleton>): Element[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll('.placeholder'),
  )
}

describe('HilosSkeleton', () => {
  it('draws the three default bars, 9, 6 and 10 columns wide', () => {
    const drawn = bars(mount())

    expect(drawn).toHaveLength(3)
    expect(drawn[0].classList.contains('col-9')).toBe(true)
    expect(drawn[1].classList.contains('col-6')).toBe(true)
    expect(drawn[2].classList.contains('col-10')).toBe(true)
    expect(drawn[0].classList.contains('mb-2')).toBe(true)
    expect(drawn[1].classList.contains('mb-2')).toBe(true)
    expect(drawn[2].classList.contains('mb-2')).toBe(false)
    expect(drawn[0].classList.contains('placeholder')).toBe(true)
  })

  it('draws the bars it is given', () => {
    const drawn = bars(mount({ lines: [4, 12] }))

    expect(drawn).toHaveLength(2)
    expect(drawn[0].classList.contains('col-4')).toBe(true)
    expect(drawn[1].classList.contains('col-12')).toBe(true)
  })

  it('hides every bar from a screen reader', () => {
    for (const bar of bars(mount())) {
      expect(bar.getAttribute('aria-hidden')).toBe('true')
    }
  })

  it('stays silent without a label', () => {
    const fixture = mount()

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[role="status"]',
      ),
    ).toHaveLength(0)
  })

  it('announces a label once for the whole frame', () => {
    const fixture = mount({ label: 'Loading…' })
    const status = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[role="status"]',
    )

    expect(status).toHaveLength(1)
    expect(status[0].textContent).toBe('Loading…')
  })
})
