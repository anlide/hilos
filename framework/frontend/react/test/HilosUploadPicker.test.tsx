// The React entry of the shared upload picker cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import type { HilosUploadField } from '@hilos/core/base'
import { act, render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { registerUploadPickerCases } from '../../test-support/uploadPickerCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import { HilosUploadPicker } from '../src/HilosUploadPicker.js'

/** Mount the real component and wrap changes in React's act boundary. */
async function mountUploadPicker(
  field: HilosUploadField,
  accept?: string,
): Promise<UploadView> {
  const container = document.body.appendChild(document.createElement('div'))
  try {
    const { unmount } = render(
      <HilosUploadPicker
        field={field}
        dataId="upload-picker"
        accept={accept}
      />,
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

registerUploadPickerCases(
  'React',
  { describe, it, expect, vi },
  mountUploadPicker,
)
