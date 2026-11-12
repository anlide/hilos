// The Angular entry of the shared upload drop cases.
// Steps and assertions live in test-support; this adapter owns only lifecycle.
import { Component } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import type { HilosUploadField } from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'

import { registerUploadDropCases } from '../../test-support/uploadDropCases.js'
import type { UploadView } from '../../test-support/uploadFieldTestSupport.js'
import { HilosUploadDrop } from '../src/HilosUploadDrop.js'

/** Project real content through the drop component without a test-only input. */
@Component({
  selector: 'test-upload-drop-host',
  imports: [HilosUploadDrop],
  template: `
    <hilos-upload-drop [field]="field" dataId="upload">
      <textarea data-id="content"></textarea>
    </hilos-upload-drop>
  `,
})
class UploadDropHost {
  field!: HilosUploadField
}

/** Mount the real component and drive Angular's change detection. */
async function mountUploadDrop(field: HilosUploadField): Promise<UploadView> {
  const fixture = TestBed.createComponent(UploadDropHost)
  const element: HTMLElement = fixture.nativeElement
  try {
    fixture.componentInstance.field = field
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

registerUploadDropCases(
  'Angular',
  { describe, it, expect, vi },
  mountUploadDrop,
)
