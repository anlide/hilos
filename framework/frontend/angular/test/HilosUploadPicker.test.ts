// The Angular entry of the shared upload picker cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import { TestBed } from '@angular/core/testing'
import type { HilosUploadField } from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'

import { registerUploadPickerCases } from '../../test-support/uploadPickerCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import { HilosUploadPicker } from '../src/HilosUploadPicker.js'

/** Mount the real component and drive Angular's change detection. */
async function mountUploadPicker(
  field: HilosUploadField,
  accept?: string,
): Promise<UploadView> {
  const fixture = TestBed.createComponent(HilosUploadPicker)
  const element: HTMLElement = fixture.nativeElement
  try {
    fixture.componentRef.setInput('field', field)
    fixture.componentRef.setInput('dataId', 'upload-picker')
    if (accept !== undefined) {
      fixture.componentRef.setInput('accept', accept)
    }
    fixture.detectChanges()
    return {
      root: element,
      async update(change) {
        change()
        fixture.detectChanges()
        await fixture.whenStable()
      },
      async flush() {
        fixture.detectChanges()
        await fixture.whenStable()
      },
      async unmount() {
        fixture.destroy()
        element.remove()
      },
    }
  } catch (error) {
    fixture.destroy()
    element.remove()
    throw error
  }
}

registerUploadPickerCases(
  'Angular',
  { describe, it, expect, vi },
  mountUploadPicker,
)
