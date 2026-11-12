// React providers, native events and lifecycle for the shared ToastHost union.
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { registerToastHostCases } from '../../test-support/toastHostCases.js'
import type { WithToastHost } from '../../test-support/toastHostFixtures.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { HilosToastHost } from '../src/HilosToastHost.js'

/** Destroy React even when mounting or a shared step fails. */
const withToastHost: WithToastHost = async ({ store, router }, steps) => {
  const container = document.body.appendChild(document.createElement('div'))
  const previousFocus = document.activeElement
  try {
    const view = render(
      <HilosRouterContext.Provider value={router}>
        <HilosToastHost store={store} />
      </HilosRouterContext.Provider>,
      { container },
    )
    await steps({
      root: container,
      async flush(change) {
        await act(async () => {
          change?.()
        })
      },
      async click(target, init) {
        fireEvent.click(target, init)
      },
      async mouseOver(target) {
        fireEvent.mouseOver(target)
      },
      async mouseLeave(target) {
        fireEvent.mouseLeave(target)
      },
      async unmount() {
        view.unmount()
      },
    })
  } finally {
    try {
      cleanup()
    } finally {
      container.remove()
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus()
      }
    }
  }
}

registerToastHostCases('React', { describe, it, expect, vi }, withToastHost)
