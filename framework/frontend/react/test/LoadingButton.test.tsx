import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useState } from 'react'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'

import { LoadingButton } from '../src/LoadingButton.js'
import type { LoadingButtonProps } from '../src/LoadingButton.js'
import {
  HilosAdminViewModeContext,
  HilosTakeoverViewOnlyContext,
} from '../src/hilosLookOnly.js'

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

  it('locks at once while loading, marked busy, without turning itself off', () => {
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
    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(button)
    expect(clicks).toBe(0)
  })
})

describe('LoadingButton in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  /**
   * Render the button labeled Save, with a way to pass it new props.
   *
   * @param props The props the caller passes first.
   */
  function renderButton(props: LoadingButtonProps = {}) {
    const view = render(<LoadingButton {...props}>Save</LoadingButton>)

    return {
      button: screen.getByRole('button') as HTMLButtonElement,
      update(next: LoadingButtonProps): void {
        view.rerender(<LoadingButton {...next}>Save</LoadingButton>)
      },
      unmount: view.unmount,
    }
  }

  /**
   * What the button tells a sighted person and a screen reader right now.
   *
   * @param button The rendered button.
   */
  function stateOf(button: HTMLButtonElement) {
    return {
      nativeDisabled: button.disabled,
      disabledLook: button.classList.contains('disabled'),
      spinner:
        button.querySelector('[data-id="loading-button-spinner"]') !== null,
      labelHidden:
        button.firstElementChild?.classList.contains('invisible') ?? false,
      ariaBusy: button.getAttribute('aria-busy'),
      ariaDisabled: button.getAttribute('aria-disabled'),
    }
  }

  const resting = {
    nativeDisabled: false,
    disabledLook: false,
    spinner: false,
    labelHidden: false,
    ariaBusy: null,
    ariaDisabled: null,
  }
  const lockedLookingTheSame = {
    ...resting,
    ariaBusy: 'true',
    ariaDisabled: 'true',
  }
  const spinning = {
    ...lockedLookingTheSame,
    disabledLook: true,
    spinner: true,
    labelHidden: true,
  }

  /**
   * Advance the fake clock and let the view render what the timer decided.
   *
   * @param ms Milliseconds to advance.
   */
  function wait(ms: number): void {
    act(() => {
      vi.advanceTimersByTime(ms)
    })
  }

  /**
   * Press the button the way a mouse, Enter, or Space activates it.
   *
   * @param button The rendered button.
   * @returns The click event, to read whether it was canceled.
   */
  function press(button: HTMLButtonElement): MouseEvent {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true })
    act(() => {
      button.dispatchEvent(event)
    })

    return event
  }

  it('keeps its look until the spinner comes at 300 ms, then looks disabled', () => {
    const onClick = vi.fn()
    const { button, update } = renderButton({ onClick })

    update({ onClick, loading: true })
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(299)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(1)
    expect(stateOf(button)).toEqual(spinning)
    expect(button.textContent).toContain('Save')

    update({ onClick, loading: false })
    expect(stateOf(button)).toEqual(resting)
    fireEvent.click(button)
    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('never shows the spinner when the reply comes before 300 ms', () => {
    const { button, update } = renderButton()

    update({ loading: true })
    wait(299)
    update({ loading: false })
    expect(stateOf(button)).toEqual(resting)
    wait(1000)
    expect(stateOf(button)).toEqual(resting)
  })

  it('gives a new request a full delay of its own', () => {
    const { button, update } = renderButton()

    update({ loading: true })
    wait(200)
    update({ loading: false })
    update({ loading: true })
    wait(200)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(100)
    expect(stateOf(button)).toEqual(spinning)
  })

  it('waits its own loadingDelay, and a new delay replaces the pending timer', () => {
    const { button, update } = renderButton({ loadingDelay: 500 })

    update({ loadingDelay: 500, loading: true })
    wait(499)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(1)
    expect(stateOf(button)).toEqual(spinning)

    update({ loadingDelay: 100, loading: false })
    update({ loadingDelay: 100, loading: true })
    wait(99)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(1)
    expect(stateOf(button)).toEqual(spinning)

    update({ loadingDelay: 500, loading: false })
    update({ loadingDelay: 500, loading: true })
    wait(200)
    update({ loadingDelay: 100, loading: true })
    expect(vi.getTimerCount()).toBe(1)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(100)
    expect(stateOf(button)).toEqual(spinning)
  })

  it('cancels the pending spinner when it unmounts', () => {
    const { update, unmount } = renderButton()

    update({ loading: true })
    expect(vi.getTimerCount()).toBe(1)
    unmount()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('cancels a blocked press before it submits the form or reaches an ancestor', () => {
    const onClick = vi.fn()
    const submitted = vi.fn((event: { preventDefault(): void }) =>
      event.preventDefault(),
    )
    const ancestorClicked = vi.fn()
    const outsideClicked = vi.fn()
    document.body.addEventListener('click', outsideClicked)

    /**
     * The button inside a form whose clicks and submits are counted.
     *
     * @param props The props of the button.
     * @param props.loading Whether the button's action is in flight.
     */
    function InForm({ loading }: { loading: boolean }) {
      return (
        <form onSubmit={submitted} onClick={ancestorClicked}>
          <LoadingButton type="submit" loading={loading} onClick={onClick}>
            Save
          </LoadingButton>
        </form>
      )
    }

    try {
      const { rerender } = render(<InForm loading />)
      const button = screen.getByRole('button') as HTMLButtonElement

      expect(press(button).defaultPrevented).toBe(true)
      wait(300)
      expect(press(button).defaultPrevented).toBe(true)
      expect(onClick).not.toHaveBeenCalled()
      expect(ancestorClicked).not.toHaveBeenCalled()
      expect(outsideClicked).not.toHaveBeenCalled()
      expect(submitted).not.toHaveBeenCalled()

      rerender(<InForm loading={false} />)
      expect(press(button).defaultPrevented).toBe(false)
      expect(onClick).toHaveBeenCalledTimes(1)
      expect(ancestorClicked).toHaveBeenCalledTimes(1)
      expect(outsideClicked).toHaveBeenCalledTimes(1)
      expect(submitted).toHaveBeenCalledTimes(1)
    } finally {
      document.body.removeEventListener('click', outsideClicked)
    }
  })

  it("leaves a direct submit to the form's own guard", () => {
    const sent = vi.fn()

    /** A form that sends once and holds every submit while its send flies. */
    function GuardedForm() {
      const [busy, setBusy] = useState(false)

      return (
        <form
          aria-label="Profile"
          onSubmit={(event) => {
            event.preventDefault()
            if (busy) {
              return
            }
            setBusy(true)
            sent()
          }}
        >
          <LoadingButton type="submit" loading={busy}>
            Save
          </LoadingButton>
        </form>
      )
    }

    render(<GuardedForm />)
    const button = screen.getByRole('button') as HTMLButtonElement
    const form = screen.getByRole('form') as HTMLFormElement

    press(button)
    expect(sent).toHaveBeenCalledTimes(1)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)

    expect(press(button).defaultPrevented).toBe(true)
    act(() => {
      form.requestSubmit(button)
    })
    fireEvent.submit(form)
    expect(sent).toHaveBeenCalledTimes(1)
  })

  it('keeps the focus on the button through its own request', () => {
    const { button, update } = renderButton()
    button.focus()

    update({ loading: true })
    expect(document.activeElement).toBe(button)
    wait(300)
    expect(document.activeElement).toBe(button)
    update({ loading: false })
    expect(document.activeElement).toBe(button)
  })

  it('turns off at once for a reason that comes while it loads', () => {
    const { button, update } = renderButton({ loading: true })

    update({ loading: true, disabled: true })
    expect(stateOf(button)).toEqual({
      ...lockedLookingTheSame,
      nativeDisabled: true,
    })
    wait(300)
    expect(stateOf(button)).toEqual({ ...spinning, nativeDisabled: true })
    update({ loading: false, disabled: true })
    expect(stateOf(button)).toEqual({
      ...resting,
      nativeDisabled: true,
      ariaDisabled: 'true',
    })
  })

  it.each([
    [
      'the admin view mode',
      HilosAdminViewModeContext,
      'hilos-view-mode-strip-text',
    ],
    [
      'a takeover that only looks',
      HilosTakeoverViewOnlyContext,
      'hilos-impersonation-strip-text',
    ],
  ])(
    'turns off at once when %s comes while it loads',
    (_mode, Context, strip) => {
      /**
       * The button inside the region of the mode.
       *
       * @param props The state of the region and of the button.
       * @param props.locked Whether the region may only look.
       * @param props.loading Whether the button's action is in flight.
       */
      function InRegion({
        locked,
        loading,
      }: {
        locked: boolean
        loading: boolean
      }) {
        return (
          <Context.Provider value={locked}>
            <LoadingButton loading={loading}>Save</LoadingButton>
          </Context.Provider>
        )
      }

      const { rerender } = render(<InRegion locked={false} loading />)
      const button = screen.getByRole('button') as HTMLButtonElement

      rerender(<InRegion locked loading />)
      expect(stateOf(button)).toEqual({
        ...lockedLookingTheSame,
        nativeDisabled: true,
      })
      expect(button.getAttribute('aria-describedby')).toBe(strip)
      rerender(<InRegion locked loading={false} />)
      expect(stateOf(button)).toEqual({
        ...resting,
        nativeDisabled: true,
        ariaDisabled: 'true',
      })
      expect(button.getAttribute('aria-describedby')).toBe(strip)
    },
  )

  it("keeps the caller's attributes, but not over its own busy lock", () => {
    const onClick = vi.fn()
    const theirs: LoadingButtonProps & { 'data-id': string } = {
      className: 'btn-primary',
      'data-id': 'profile-save',
      'aria-describedby': 'own-reason',
      'aria-busy': 'false',
      'aria-disabled': 'false',
      onClick,
    }
    const { button, update } = renderButton({ ...theirs, loading: true })

    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(button)
    expect(onClick).not.toHaveBeenCalled()
    wait(300)
    expect(button.className).toBe('btn position-relative btn-primary disabled')
    expect(button.getAttribute('data-id')).toBe('profile-save')
    expect(button.getAttribute('aria-describedby')).toBe('own-reason')
    expect(button.getAttribute('aria-disabled')).toBe('true')

    update({ ...theirs, loading: false })
    expect(button.className).toBe('btn position-relative btn-primary')
    expect(button.getAttribute('aria-busy')).toBe('false')
    expect(button.getAttribute('aria-disabled')).toBe('false')

    update({ ...theirs, disabled: true })
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-disabled')).toBe('true')
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
    const onClick = vi.fn()
    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton opensWindow disabled onClick={onClick}>
          Open
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const disabled = screen.getByRole('button') as HTMLButtonElement
    expect(disabled.disabled).toBe(true)
    expect(disabled.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(disabled)
    expect(onClick).not.toHaveBeenCalled()
    cleanup()

    render(
      <HilosAdminViewModeContext.Provider value>
        <LoadingButton opensWindow loading onClick={onClick}>
          Open
        </LoadingButton>
      </HilosAdminViewModeContext.Provider>,
    )
    const loading = screen.getByRole('button') as HTMLButtonElement
    expect(loading.disabled).toBe(false)
    expect(loading.getAttribute('aria-busy')).toBe('true')
    expect(loading.getAttribute('aria-disabled')).toBe('true')
    expect(loading.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(loading)
    expect(onClick).not.toHaveBeenCalled()
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
