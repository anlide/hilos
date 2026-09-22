import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'

import { HilosSwitch } from '../src/HilosSwitch.js'

function input(): HTMLInputElement {
  return screen.getByRole('switch') as HTMLInputElement
}

describe('HilosSwitch', () => {
  afterEach(cleanup)

  it('keeps its position on click and reports the requested inversion', () => {
    const toggles: boolean[] = []
    const view = render(
      <HilosSwitch
        checked={false}
        dataId="setting-toggle"
        aria-label="Enable setting"
        onToggle={(next) => toggles.push(next)}
      />,
    )

    expect(fireEvent.click(input())).toBe(false)
    view.rerender(
      <HilosSwitch
        checked={false}
        dataId="setting-toggle"
        aria-label="Enable setting"
        onToggle={(next) => toggles.push(next)}
      />,
    )

    expect(input().checked).toBe(false)
    expect(toggles).toEqual([true])
  })

  it('follows the checked input', () => {
    const { rerender } = render(
      <HilosSwitch
        checked={false}
        dataId="setting-toggle"
        aria-label="Enable setting"
        onToggle={() => undefined}
      />,
    )

    rerender(
      <HilosSwitch
        checked
        dataId="setting-toggle"
        aria-label="Enable setting"
        onToggle={() => undefined}
      />,
    )

    expect(input().checked).toBe(true)
  })

  it('disables and marks the input busy while saving', () => {
    render(
      <HilosSwitch
        checked={false}
        busy
        dataId="setting-toggle"
        aria-label="Enable setting"
        onToggle={() => undefined}
      />,
    )

    expect(input().disabled).toBe(true)
    expect(input().getAttribute('aria-busy')).toBe('true')
  })

  it('shows the busy spinner only after the delay', () => {
    vi.useFakeTimers()
    try {
      const { rerender } = render(
        <HilosSwitch
          checked={false}
          dataId="setting-toggle"
          aria-label="Enable setting"
          spinnerDelay={300}
          onToggle={() => undefined}
        />,
      )
      rerender(
        <HilosSwitch
          checked={false}
          busy
          dataId="setting-toggle"
          aria-label="Enable setting"
          spinnerDelay={300}
          onToggle={() => undefined}
        />,
      )
      expect(screen.queryByRole('status')).toBeNull()

      act(() => {
        vi.advanceTimersByTime(300)
      })

      expect(screen.getByRole('status')).toBeTruthy()
    } finally {
      vi.useRealTimers()
    }
  })

  it('gives each instance an id and points its label at its own input', () => {
    const { container } = render(
      <>
        <HilosSwitch
          checked={false}
          dataId="first"
          label="First"
          onToggle={() => undefined}
        />
        <HilosSwitch
          checked={false}
          dataId="second"
          label="Second"
          onToggle={() => undefined}
        />
      </>,
    )
    const inputs = Array.from(container.querySelectorAll('input'))
    const labels = Array.from(container.querySelectorAll('label'))

    expect(inputs[0]?.id).not.toBe(inputs[1]?.id)
    expect(labels[0]?.htmlFor).toBe(inputs[0]?.id)
    expect(labels[1]?.htmlFor).toBe(inputs[1]?.id)
  })
})
