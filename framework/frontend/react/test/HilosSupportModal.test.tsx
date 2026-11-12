// React input, event and lifecycle delivery for the shared support dialog cases.
import { cleanup, fireEvent, render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  registerSupportModalCases,
  type WithSupportModal,
} from '../../test-support/supportModalCases.js'
import { HilosSupportModal } from '../src/public/HilosSupportModal.js'

/** Release React's roots and portals even when render or a later step throws. */
const withSupportModal: WithSupportModal = async (initialOpen, steps) => {
  const originalNodes = new Set(document.body.childNodes)
  const modalOpen = document.body.classList.contains('modal-open')
  const previousFocus = document.activeElement
  const container = document.body.appendChild(document.createElement('div'))
  let rerender: ReturnType<typeof render>['rerender'] | undefined
  const modal = (open: boolean) => (
    <HilosSupportModal open={open} onClose={() => rerender?.(modal(false))} />
  )
  try {
    const view = render(modal(initialOpen), { container })
    rerender = view.rerender
    await steps({
      root: document.body,
      async update(open) {
        view.rerender(modal(open))
      },
      async click(target) {
        fireEvent.click(target)
      },
    })
  } finally {
    try {
      cleanup()
    } finally {
      for (const node of [...document.body.childNodes]) {
        if (!originalNodes.has(node)) node.remove()
      }
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus()
      }
    }
  }
}

registerSupportModalCases('React', { describe, it, expect }, withSupportModal)
