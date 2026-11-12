// React lifecycle and context for ErrorPage and HilosView's error/auth cases.
import { act, cleanup, render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AUTH_SURFACE_HEADING_ID } from '@hilos/core/base'

import {
  registerErrorPageCases,
  type WithErrorPage,
} from '../../test-support/errorPageCases.js'
import {
  registerPageErrorViewCases,
  type WithPageErrorView,
} from '../../test-support/pageErrorViewCases.js'
import { ErrorPage } from '../src/ErrorPage.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** cleanup owns React's roots even if initial rendering throws. */
const withErrorPage: WithErrorPage = async (error, steps) => {
  const previousFocus = document.activeElement
  const container = document.body.appendChild(document.createElement('div'))
  try {
    const view = render(<ErrorPage error={error} />, { container })
    await steps({
      root: container,
      async update(value) {
        view.rerender(<ErrorPage error={value} />)
      },
    })
  } finally {
    try {
      cleanup()
    } finally {
      container.remove()
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}

/** Mount the real context consumer, including the real portaled auth modal. */
const withPageErrorView: WithPageErrorView = async (input, steps) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const container = document.body.appendChild(document.createElement('div'))
  const pages = { user: () => <div data-id="user-page" /> }
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
          authSurface={AuthSurface}
          authGate={input.authGate}
        />
      </HilosRouterContext.Provider>,
      { container },
    )
    await steps({
      root: document.body,
      async flush(change) {
        await act(async () => {
          change?.()
        })
      },
    })
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

registerErrorPageCases('React', { describe, it, expect }, withErrorPage)
registerPageErrorViewCases('React', { describe, it, expect }, withPageErrorView)
