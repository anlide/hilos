import { act } from '@testing-library/react'
import { createRoot } from 'react-dom/client'
import { describe, expect, it } from 'vitest'
import {
  createLinkRouter,
  registerLinkCases,
  type LinkInput,
  type LinkView,
} from '../../test-support/linkCases.js'
import { HilosLink } from '../src/HilosLink.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Rebuild anchor props so an earlier caller override cannot survive update. */
async function mountLink(input: LinkInput): Promise<LinkView> {
  const router = createLinkRouter()
  const host = document.body.appendChild(document.createElement('div'))
  const root = createRoot(host)
  function draw({ to, current }: LinkInput): void {
    const attrs =
      current === undefined ? {} : { 'aria-current': current ?? undefined }
    act(() =>
      root.render(
        <HilosRouterContext.Provider value={router}>
          <HilosLink to={to} {...attrs}>
            Billing
          </HilosLink>
        </HilosRouterContext.Provider>,
      ),
    )
  }
  try {
    draw(input)
    return {
      root: host,
      async update(value) {
        draw(value)
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

registerLinkCases('React', { describe, it, expect }, mountLink)
