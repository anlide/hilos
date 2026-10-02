// The mark of a hidden value, as the Vue and React kits test theirs
// (vue/src/HilosHiddenMark.test.ts): a soft grey pill, a decorative struck-out
// eye and the word.
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { HilosHiddenMark } from '../src/HilosHiddenMark.js'

describe('HilosHiddenMark', () => {
  it('draws a soft grey pill with a decorative struck-out eye and the word', () => {
    const fixture = TestBed.createComponent(HilosHiddenMark)
    fixture.detectChanges()
    const mark = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="hilos-hidden"]',
    )

    expect(mark?.tagName).toBe('SPAN')
    expect(Array.from(mark?.classList ?? [])).toEqual([
      'badge',
      'rounded-pill',
      'bg-body-secondary',
      'text-body-secondary',
      'fw-medium',
    ])
    const icon = mark?.querySelector('i')
    expect(icon?.classList.contains('bi-eye-slash')).toBe(true)
    expect(icon?.getAttribute('aria-hidden')).toBe('true')
    expect(mark?.textContent).toBe('Hidden')
  })
})
