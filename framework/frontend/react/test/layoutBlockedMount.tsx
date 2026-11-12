import { act, cleanup, render } from '@testing-library/react'
import { useState } from 'react'
import type {
  LayoutBlockedInput,
  LayoutBlockedView,
} from '../../test-support/layoutBlockedLiveCases.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Eager caller page; open state dies with its native component instance. */
function PrivatePage() {
  const [open, setOpen] = useState(false)
  return (
    <div data-id="private-content">
      <h1>Profile</h1>
      <button type="button" data-id="open-draft" onClick={() => setOpen(true)}>
        Edit
      </button>
      <HilosModal
        open={open}
        title="Edit phone"
        confirmOnClose
        onClose={() => setOpen(false)}
      >
        <input data-id="draft" data-autofocus="" aria-label="Phone number" />
      </HilosModal>
    </div>
  )
}

function AuthSurface() {
  return (
    <form data-id="test-sign-in">
      <h1>Sign in</h1>
    </form>
  )
}

/** RTL owns the real outlet, modal portal and all effect cleanup. */
export async function mountLayoutBlocked(
  input: LayoutBlockedInput,
): Promise<LayoutBlockedView> {
  const host = document.body.appendChild(document.createElement('div'))
  let destroyed = false
  async function flush(run?: () => void): Promise<void> {
    await act(async () => {
      run?.()
      await Promise.resolve()
    })
  }
  async function unmount(): Promise<void> {
    if (destroyed) return
    destroyed = true
    try {
      await act(async () => {
        cleanup()
        await Promise.resolve()
      })
    } finally {
      host.remove()
    }
  }
  try {
    await act(async () => {
      render(
        <HilosRouterContext.Provider value={input.router}>
          <HilosLayout connection={input.connection}>
            <HilosView
              pages={{ main: PrivatePage }}
              authSurface={AuthSurface}
            />
          </HilosLayout>
        </HilosRouterContext.Provider>,
        { container: host },
      )
      await Promise.resolve()
    })
    await flush()
    return { root: host, flush, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (releaseError) {
      throw new AggregateError([error, releaseError], undefined, {
        cause: releaseError,
      })
    }
    throw error
  }
}
