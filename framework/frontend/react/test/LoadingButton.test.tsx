import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'

import { LoadingButton } from '../src/LoadingButton.js'

function spinner(container: HTMLElement): Element | null {
  return container.querySelector('[data-id="loading-button-spinner"]')
}

describe('LoadingButton', () => {
  afterEach(cleanup)

  it('renders its children', () => {
    render(<LoadingButton>Save</LoadingButton>)
    expect(screen.getByRole('button').textContent).toContain('Save')
  })

  it('calls onClick when enabled', () => {
    let clicks = 0
    render(
      <LoadingButton
        onClick={() => {
          clicks += 1
        }}
      >
        Save
      </LoadingButton>,
    )
    expect(screen.getByRole('button').getAttribute('aria-busy')).toBeNull()
    fireEvent.click(screen.getByRole('button'))
    expect(clicks).toBe(1)
  })

  it('disables and swallows clicks while loading', () => {
    let clicks = 0
    render(
      <LoadingButton
        loading
        onClick={() => {
          clicks += 1
        }}
      >
        Save
      </LoadingButton>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-busy')).toBe('true')
    fireEvent.click(button)
    expect(clicks).toBe(0)
  })

  it('shows the spinner only after the delay', () => {
    vi.useFakeTimers()
    try {
      const { container, rerender } = render(
        <LoadingButton loadingDelay={300}>Save</LoadingButton>,
      )
      rerender(
        <LoadingButton loadingDelay={300} loading>
          Save
        </LoadingButton>,
      )
      expect(spinner(container)).toBeNull()
      act(() => {
        vi.advanceTimersByTime(300)
      })
      expect(spinner(container)).not.toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })
})
