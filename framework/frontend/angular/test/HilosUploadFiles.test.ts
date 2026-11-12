// The Angular entry of the shared upload files cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import { TestBed } from '@angular/core/testing'
import type { HilosUploadField } from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'

import { registerUploadFilesCases } from '../../test-support/uploadFilesCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import { HilosUploadFiles } from '../src/HilosUploadFiles.js'

/** Mount the real component and drive Angular's change detection. */
async function mountUploadFiles(field: HilosUploadField): Promise<UploadView> {
  const fixture = TestBed.createComponent(HilosUploadFiles)
  const element: HTMLElement = fixture.nativeElement
  try {
    fixture.componentRef.setInput('field', field)
    fixture.componentRef.setInput('dataId', 'upload')
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

registerUploadFilesCases(
  'Angular',
  { describe, it, expect, vi },
  mountUploadFiles,
)
