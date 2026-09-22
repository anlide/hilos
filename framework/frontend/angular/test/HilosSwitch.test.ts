import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it, vi } from 'vitest'

import { HilosSwitch } from '../src/HilosSwitch.js'

function mount(
  inputs: { checked: boolean; busy?: boolean; spinnerDelay?: number } = {
    checked: false,
  },
): ComponentFixture<HilosSwitch> {
  const fixture = TestBed.createComponent(HilosSwitch)
  fixture.componentRef.setInput('checked', inputs.checked)
  fixture.componentRef.setInput('dataId', 'setting-toggle')
  fixture.componentRef.setInput('aria-label', 'Enable setting')
  if (inputs.busy !== undefined) {
    fixture.componentRef.setInput('busy', inputs.busy)
  }
  if (inputs.spinnerDelay !== undefined) {
    fixture.componentRef.setInput('spinnerDelay', inputs.spinnerDelay)
  }
  fixture.detectChanges()

  return fixture
}

function input(fixture: ComponentFixture<HilosSwitch>): HTMLInputElement {
  return (fixture.nativeElement as HTMLElement).querySelector(
    'input',
  ) as HTMLInputElement
}

describe('HilosSwitch', () => {
  it('keeps its position on click and emits the requested inversion', () => {
    const fixture = mount()
    const toggles: boolean[] = []
    fixture.componentInstance.toggle.subscribe((next) => toggles.push(next))

    input(fixture).click()
    fixture.detectChanges()

    expect(input(fixture).checked).toBe(false)
    expect(toggles).toEqual([true])
  })

  it('follows the checked input', () => {
    const fixture = mount()

    fixture.componentRef.setInput('checked', true)
    fixture.detectChanges()

    expect(input(fixture).checked).toBe(true)
  })

  it('disables and marks the input busy while saving', () => {
    const fixture = mount({ checked: false, busy: true })

    expect(input(fixture).disabled).toBe(true)
    expect(input(fixture).getAttribute('aria-busy')).toBe('true')
  })

  it('shows the busy spinner only after the delay', () => {
    vi.useFakeTimers()
    try {
      const fixture = mount({ checked: false, spinnerDelay: 300 })
      fixture.componentRef.setInput('busy', true)
      fixture.detectChanges()
      expect(
        (fixture.nativeElement as HTMLElement).querySelector('[role="status"]'),
      ).toBeNull()

      vi.advanceTimersByTime(300)
      fixture.detectChanges()

      expect(
        (fixture.nativeElement as HTMLElement).querySelector('[role="status"]'),
      ).not.toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('gives each instance an id and points its label at its own input', () => {
    const first = mount()
    first.componentRef.setInput('label', 'First')
    first.detectChanges()
    const second = mount()
    second.componentRef.setInput('label', 'Second')
    second.detectChanges()

    const firstInput = input(first)
    const secondInput = input(second)
    const firstLabel = (first.nativeElement as HTMLElement).querySelector(
      'label',
    )
    const secondLabel = (second.nativeElement as HTMLElement).querySelector(
      'label',
    )

    expect(firstInput.id).not.toBe(secondInput.id)
    expect(firstLabel?.htmlFor).toBe(firstInput.id)
    expect(secondLabel?.htmlFor).toBe(secondInput.id)
  })
})
