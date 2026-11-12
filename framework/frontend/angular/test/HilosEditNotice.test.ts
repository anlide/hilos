// Angular lifecycle for the shared EditNotice scenarios.
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import {
  registerEditNoticeCases,
  type EditNoticeInput,
  type EditNoticeView,
} from '../../test-support/editNoticeCases.js'
import { HilosEditNotice } from '../src/HilosEditNotice.js'

/** Mount the real component and drive its inputs through Angular. */
async function mountEditNotice(
  input: EditNoticeInput,
): Promise<EditNoticeView> {
  const fixture = TestBed.createComponent(HilosEditNotice)
  const element: HTMLElement = fixture.nativeElement
  try {
    for (const [key, value] of Object.entries(input)) {
      fixture.componentRef.setInput(key, value)
    }
    fixture.detectChanges()
    return {
      root: document.body,
      async update(patch) {
        for (const [key, value] of Object.entries(patch)) {
          fixture.componentRef.setInput(key, value)
        }
        fixture.detectChanges()
      },
      async click(target) {
        target.click()
        fixture.detectChanges()
      },
      async flush() {
        fixture.detectChanges()
        await fixture.whenStable()
      },
      async unmount() {
        try {
          fixture.destroy()
        } finally {
          element.remove()
        }
      },
    }
  } catch (error) {
    try {
      fixture.destroy()
    } finally {
      element.remove()
    }
    throw error
  }
}

registerEditNoticeCases('Angular', { describe, expect, it }, mountEditNotice)
