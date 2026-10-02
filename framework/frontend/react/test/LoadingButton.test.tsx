import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'

import { LoadingButton } from '../src/LoadingButton.js'
import {
  HilosAdminViewModeContext,
  HilosTakeoverViewOnlyContext,
} from '../src/hilosLookOnly.js'

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

describe('LoadingButton in the admin view mode', () => {
  afterEach(cleanup)

  it('stands disabled, swallows clicks, and points at the strip', () => {
    let clicks = 0
    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton
          onClick={() => {
            clicks += 1
          }}
        >
          Save
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement

    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    expect(button.textContent).toBe('Save')
    fireEvent.click(button)
    expect(clicks).toBe(0)
  })

  it("keeps the caller's own description beside the strip's", () => {
    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton
          aria-describedby="own-reason"
          className="btn-primary"
          data-id="person-block-open"
        >
          Save
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement

    expect(button.getAttribute('aria-describedby')).toBe(
      'own-reason hilos-view-mode-strip-text',
    )
    expect(button.className).toBe('btn position-relative btn-primary')
    expect(button.getAttribute('data-id')).toBe('person-block-open')
  })

  it('is untouched outside the mode and outside an admin page', () => {
    let clicks = 0
    const onClick = () => {
      clicks += 1
    }
    const { unmount } = render(
      <HilosAdminViewModeContext.Provider value={false}>
        <LoadingButton aria-describedby="own-reason" onClick={onClick}>
          Save
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const insideOff = screen.getByRole('button') as HTMLButtonElement
    expect(insideOff.disabled).toBe(false)
    expect(insideOff.getAttribute('aria-describedby')).toBe('own-reason')
    fireEvent.click(insideOff)
    expect(clicks).toBe(1)
    unmount()

    render(
      <LoadingButton aria-describedby="own-reason" onClick={onClick}>
        Save
      </LoadingButton>,
    )
    const outside = screen.getByRole('button') as HTMLButtonElement
    expect(outside.disabled).toBe(false)
    expect(outside.getAttribute('aria-describedby')).toBe('own-reason')
    fireEvent.click(outside)
    expect(clicks).toBe(2)
  })

  it('comes alive the moment the viewer is given the rights', () => {
    let clicks = 0
    const onClick = () => {
      clicks += 1
    }
    const { rerender } = render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton onClick={onClick}>Save</LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    rerender(
      <HilosAdminViewModeContext.Provider value={false}>
        <LoadingButton onClick={onClick}>Save</LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )

    const button = screen.getByRole('button') as HTMLButtonElement
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(button)
    expect(clicks).toBe(1)
  })

  it('stays live when marked as opening a window in the view mode', () => {
    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton opensWindow>Open</LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    cleanup()

    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton opensWindow aria-describedby="own-reason">
          Open
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const withOwnDesc = screen.getByRole('button') as HTMLButtonElement
    expect(withOwnDesc.disabled).toBe(false)
    expect(withOwnDesc.getAttribute('aria-describedby')).toBe('own-reason')
  })

  it('honors disabled and loading on an opensWindow button in the view mode', () => {
    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton opensWindow disabled>
          Open
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const disabled = screen.getByRole('button') as HTMLButtonElement
    expect(disabled.disabled).toBe(true)
    expect(disabled.getAttribute('aria-describedby')).toBeNull()
    cleanup()

    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton opensWindow loading>
          Open
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const loading = screen.getByRole('button') as HTMLButtonElement
    expect(loading.disabled).toBe(true)
    expect(loading.getAttribute('aria-describedby')).toBeNull()
  })

  it('leaves an opensWindow button untouched outside the view mode', () => {
    render(
      <HilosAdminViewModeContext.Provider value={false}>
        <LoadingButton opensWindow>Open</LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
  })
})

describe('LoadingButton in a takeover that only looks (HIL-1170)', () => {
  afterEach(cleanup)

  it('names both strips when the page is also seen in the admin view mode', () => {
    render(
      <HilosAdminViewModeContext.Provider value>
        <HilosTakeoverViewOnlyContext.Provider value>
          <LoadingButton>Send</LoadingButton>
        </HilosTakeoverViewOnlyContext.Provider>
      </HilosAdminViewModeContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement

    expect(button.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text hilos-impersonation-strip-text',
    )
  })

  it('stands disabled, swallows clicks, and points at the impersonation strip beside its own', () => {
    let clicks = 0
    render(
      <HilosTakeoverViewOnlyContext.Provider value>
        <LoadingButton
          aria-describedby="row-reason"
          onClick={() => {
            clicks += 1
          }}
        >
          Send
        </LoadingButton>
      </HilosTakeoverViewOnlyContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement

    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toBe(
      'row-reason hilos-impersonation-strip-text',
    )
    expect(button.textContent).toBe('Send')
    fireEvent.click(button)
    expect(clicks).toBe(0)
  })

  it('is live while the takeover may act', () => {
    let clicks = 0
    render(
      <HilosTakeoverViewOnlyContext.Provider value={false}>
        <LoadingButton
          onClick={() => {
            clicks += 1
          }}
        >
          Send
        </LoadingButton>
      </HilosTakeoverViewOnlyContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(button)
    expect(clicks).toBe(1)
  })

  it('stays live when it only opens a window', () => {
    let clicks = 0
    render(
      <HilosTakeoverViewOnlyContext.Provider value>
        <LoadingButton
          opensWindow
          onClick={() => {
            clicks += 1
          }}
        >
          Open
        </LoadingButton>
      </HilosTakeoverViewOnlyContext.Provider>,
    )
    const button = screen.getByRole('button') as HTMLButtonElement

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(button)
    expect(clicks).toBe(1)
  })
})
