import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { ActionError, createSignal, hilosToasts } from '@hilos/core'
import type { ActionHandle } from '@hilos/core'

import { useTrackedAction } from '../src/useTrackedAction.js'

// A handle stand-in: the lifecycle's loading signal plus a settled `done` promise
// — enough to drive the hook without a real connection.
function handle(done: Promise<string | undefined>): ActionHandle {
  return { requestId: '1', loading: createSignal(false), done }
}

// `make` builds the (possibly rejecting) promise at click time, so a rejected
// promise is never left unhandled between render and the click.
function Probe({
  make,
  onResult,
  toast,
}: {
  make: () => Promise<string | undefined>
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
        make={() => Promise.resolve(undefined)}
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
    render(<Probe make={() => Promise.resolve('Saved.')} onResult={() => {}} />)
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(toastStack()).toEqual([{ severity: 'success', message: 'Saved.' }])
  })

  it('toasts a generic fallback when the backend sent no message', async () => {
    render(
      <Probe make={() => Promise.resolve(undefined)} onResult={() => {}} />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(toastStack()).toEqual([{ severity: 'success', message: 'Done.' }])
  })

  it('suppresses the success toast when toast is false', async () => {
    render(
      <Probe
        make={() => Promise.resolve('Saved.')}
        onResult={() => {}}
        toast={false}
      />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(toastStack()).toEqual([])
  })

  it('resolves false and surfaces a generic error on failure', async () => {
    let result: boolean | undefined
    render(
      <Probe
        make={() =>
          Promise.reject(new ActionError('setting_update', 'fail', 'nope'))
        }
        onResult={(ok) => (result = ok)}
      />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(result).toBe(false)
    expect(screen.getByTestId('error').textContent).toContain(
      'could not be completed',
    )
    expect(screen.getByTestId('busy').textContent).toBe('false')
  })
})
