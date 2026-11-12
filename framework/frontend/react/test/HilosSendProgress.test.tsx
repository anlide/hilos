// React lifecycle for the shared SendProgress scenarios.
import { act, fireEvent, render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import {
  registerSendProgressCases,
  type SendProgressInput,
  type SendProgressView,
} from '../../test-support/sendProgressCases.js'
import { HilosSendProgress } from '../src/HilosSendProgress.js'

/** Mount the real component, including its portaled details panel. */
async function mountSendProgress(
  input: SendProgressInput,
): Promise<SendProgressView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    let current = input
    const { rerender, unmount } = render(<HilosSendProgress {...current} />, {
      container,
    })
    return {
      root: document.body,
      async update(patch) {
        current = { ...current, ...patch }
        rerender(<HilosSendProgress {...current} />)
      },
      async click(target) {
        fireEvent.click(target)
      },
      async flush(change) {
        await act(async () => {
          change?.()
        })
      },
      async unmount() {
        try {
          unmount()
        } finally {
          container.remove()
        }
      },
    }
  } catch (error) {
    container.remove()
    throw error
  }
}

registerSendProgressCases(
  'React',
  { describe, expect, it, vi },
  mountSendProgress,
)
