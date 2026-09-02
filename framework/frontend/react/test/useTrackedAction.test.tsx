import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { ActionError, createSignal, hilosToasts } from '@hilos/core'
import type { ActionHandle, ActionResult } from '@hilos/core'

import { useTrackedAction } from '../src/useTrackedAction.js'

// A handle stand-in: the lifecycle's loading signal plus a settled `done` promise
// — enough to drive the hook without a real connection.
function handle(done: Promise<ActionResult>): ActionHandle {
  return { requestId: '1', loading: createSignal(false), done }
}

// `make` builds the (possibly rejecting) promise at click time, so a rejected
// promise is never left unhandled between render and the click.
function Probe({
  make,
  onResult,
  toast,
}: {
  make: () => Promise<ActionResult>
  onResult: (ok: boolean) => void
  toast?: boolean
}) {
  const tracked = useTrackedAction({ toast })
  return (
    <div>
      <span data-testid="busy">{String(tracked.busy)}</span>
      <span data-testid="error">{tracked.error ?? ''}</span>
      <button
        data-testid="go"
        onClick={() => void tracked.run(handle(make())).then(onResult)}
      >
        go
      </button>
    </div>
  )
}

/** The severity/message of the toasts currently in the shared stack. */
function toastStack(): { severity: string; message: string }[] {
  return hilosToasts.toasts.get().map((toast) => ({
    severity: toast.severity,
    message: toast.message,
  }))
}

describe('useTrackedAction', () => {
  afterEach(() => {
    cleanup()
    hilosToasts.clear()
  })

  it('resolves true and stays clear on success', async () => {
    let result: boolean | undefined
    render(
      <Probe
        make={() => Promise.resolve({})}
        onResult={(ok) => (result = ok)}
      />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(result).toBe(true)
    expect(screen.getByTestId('error').textContent).toBe('')
    expect(screen.getByTestId('busy').textContent).toBe('false')
  })

  it('toasts the backend success message on success', async () => {
    render(
      <Probe
        make={() => Promise.resolve({ message: 'Saved.' })}
        onResult={() => {}}
      />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(toastStack()).toEqual([{ severity: 'success', message: 'Saved.' }])
  })

  it('stays silent when the backend sent no message', async () => {
    render(<Probe make={() => Promise.resolve({})} onResult={() => {}} />)
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(toastStack()).toEqual([])
  })

  it('suppresses the success toast when toast is false', async () => {
    render(
      <Probe
        make={() => Promise.resolve({ message: 'Saved.' })}
        onResult={() => {}}
        toast={false}
      />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(toastStack()).toEqual([])
  })

  it('resolves false and surfaces the backend phrase on failure', async () => {
    // The refusal the backend sent is what the person reads. Until HIL-779 this
    // case asserted the opposite - the driver replaced it with its own sentence,
    // and this test held that behaviour in place.
    let result: boolean | undefined
    render(
      <Probe
        make={() =>
          Promise.reject(
            new ActionError(
              'setting_update',
              'fail',
              'Value must be an integer of 0 or more',
            ),
          )
        }
        onResult={(ok) => (result = ok)}
      />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(result).toBe(false)
    expect(screen.getByTestId('error').textContent).toBe(
      'Value must be an integer of 0 or more',
    )
    expect(screen.getByTestId('busy').textContent).toBe('false')
  })

  it('keeps its own phrasing when no backend answer arrived', async () => {
    // A timeout has no backend sentence to print: nothing came back at all.
    render(
      <Probe
        make={() =>
          Promise.reject(
            new ActionError('setting_update', 'timeout', 'Timed out.'),
          )
        }
        onResult={() => {}}
      />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(screen.getByTestId('error').textContent).toContain('timed out')
  })
})
