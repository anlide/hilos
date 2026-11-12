// React lifecycle for the shared EditNotice scenarios.
import { act, fireEvent, render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  registerEditNoticeCases,
  type EditNoticeInput,
  type EditNoticeView,
} from '../../test-support/editNoticeCases.js'
import { HilosEditNotice } from '../src/HilosEditNotice.js'

/** Mount the real component, including its portaled details panel. */
async function mountEditNotice(
  input: EditNoticeInput,
): Promise<EditNoticeView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    let current = input
    const { rerender, unmount } = render(<HilosEditNotice {...current} />, {
      container,
    })
    return {
      root: document.body,
      async update(patch) {
        current = { ...current, ...patch }
        rerender(<HilosEditNotice {...current} />)
      },
      async click(target) {
        fireEvent.click(target)
      },
      async flush() {
        await act(async () => {})
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

registerEditNoticeCases('React', { describe, expect, it }, mountEditNotice)
