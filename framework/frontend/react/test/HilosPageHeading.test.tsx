import { act } from '@testing-library/react'
import { createRoot } from 'react-dom/client'
import { describe, expect, it } from 'vitest'
import type { HilosPageIdentity } from '@hilos/core/base'
import {
  createPageHeadingRouter,
  registerPageHeadingCases,
  type PageHeadingView,
} from '../../test-support/pageHeadingCases.js'
import { HilosPageHeading } from '../src/HilosPageHeading.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Render the real heading and release its signal bridge before its host. */
async function mountHeading(
  identity?: HilosPageIdentity,
): Promise<PageHeadingView> {
  const { router, pageIdentity } = createPageHeadingRouter(identity)
  const host = document.body.appendChild(document.createElement('div'))
  const root = createRoot(host)
  try {
    act(() =>
      root.render(
        <HilosRouterContext.Provider value={router}>
          <HilosPageHeading dataId="test-heading" />
        </HilosRouterContext.Provider>,
      ),
    )
    return {
      root: host,
      async update(value) {
        act(() => pageIdentity.set(value))
      },
      async unmount() {
        try {
          act(() => root.unmount())
        } finally {
          host.remove()
        }
      },
    }
  } catch (error) {
    try {
      act(() => root.unmount())
    } finally {
      host.remove()
    }
    throw error
  }
}

registerPageHeadingCases('React', { describe, it, expect }, mountHeading)
