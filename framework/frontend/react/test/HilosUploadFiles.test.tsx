// The React entry of the shared upload files cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import type { HilosUploadField } from '@hilos/core/base'
import { act, render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { registerUploadFilesCases } from '../../test-support/uploadFilesCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import { HilosUploadFiles } from '../src/HilosUploadFiles.js'

/** Mount the real component and wrap changes in React's act boundary. */
async function mountUploadFiles(field: HilosUploadField): Promise<UploadView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    const { unmount } = render(
      <HilosUploadFiles field={field} dataId="upload" />,
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

registerUploadFilesCases(
  'React',
  { describe, it, expect, vi },
  mountUploadFiles,
)
