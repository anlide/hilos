// The React entry of the shared upload drop cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import type { HilosUploadField } from '@hilos/core/base'
import { act, render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { registerUploadDropCases } from '../../test-support/uploadDropCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import { HilosUploadDrop } from '../src/HilosUploadDrop.js'

/** Mount the real component and wrap changes in React's act boundary. */
async function mountUploadDrop(field: HilosUploadField): Promise<UploadView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    const { unmount } = render(
      <HilosUploadDrop field={field} dataId="upload">
        <textarea data-id="content"></textarea>
      </HilosUploadDrop>,
      { container },
    )
    return {
      root: container,
      async update(change) {
        await act(async () => {
          change()
        })
      },
      async flush() {
        await act(async () => {})
      },
      async unmount() {
        unmount()
        container.remove()
      },
    }
  } catch (error) {
    container.remove()
    throw error
  }
}

registerUploadDropCases('React', { describe, it, expect, vi }, mountUploadDrop)
