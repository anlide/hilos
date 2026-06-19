import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { ActionError, createSignal } from '@hilos/core'
import type { ActionHandle } from '@hilos/core'

import { useTrackedAction } from '../src/useTrackedAction.js'

// A handle stand-in: the lifecycle's loading signal plus a settled `done` promise
// — enough to drive the hook without a real connection.
function handle(done: Promise<void>): ActionHandle {
  return { requestId: '1', loading: createSignal(false), done }
}

// `make` builds the (possibly rejecting) promise at click time, so a rejected
// promise is never left unhandled between render and the click.
function Probe({
  make,
  onResult,
}: {
  make: () => Promise<void>
  onResult: (ok: boolean) => void
}) {
  const tracked = useTrackedAction()
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

describe('useTrackedAction', () => {
  afterEach(cleanup)

  it('resolves true and stays clear on success', async () => {
    let result: boolean | undefined
    render(
      <Probe make={() => Promise.resolve()} onResult={(ok) => (result = ok)} />,
    )
    await act(async () => {
      fireEvent.click(screen.getByTestId('go'))
    })
    expect(result).toBe(true)
    expect(screen.getByTestId('error').textContent).toBe('')
    expect(screen.getByTestId('busy').textContent).toBe('false')
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
