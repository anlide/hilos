// React context, real portal lifecycle and act around external input changes.
import { act, cleanup, render } from '@testing-library/react'
import { AUTH_SURFACE_HEADING_ID } from '@hilos/core/base'

import type { WithHilosViewOutlet } from '../../test-support/hilosViewFixtures.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

export const withHilosViewOutlet: WithHilosViewOutlet = async (
  input,
  steps,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const container = document.body.appendChild(document.createElement('div'))
  const pages = {
    main: () => <div data-id="main">Leaving page</div>,
    known: () => <div data-id="known-page">Known</div>,
  }
  const pageSkeletons = input.withSkeletons
    ? { main: () => <div data-id="main-skeleton" /> }
    : undefined
  const AuthSurface = input.authSurface
    ? () => (
        <div data-id="auth-surface">
          {input.authSurface?.heading === undefined ? null : (
            <h2 id={AUTH_SURFACE_HEADING_ID}>{input.authSurface.heading}</h2>
          )}
        </div>
      )
    : undefined
  try {
    render(
      <HilosRouterContext.Provider value={input.router}>
        <HilosView
          pages={pages}
          pageSkeletons={pageSkeletons}
          authSurface={AuthSurface}
          authGate={input.authGate}
        />
      </HilosRouterContext.Provider>,
      { container },
    )
    const flush = async (change?: () => void) => {
      await act(async () => {
        change?.()
      })
    }
    await flush()
    await steps({ root: container, flush })
  } finally {
    try {
      cleanup()
    } finally {
      container.remove()
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
