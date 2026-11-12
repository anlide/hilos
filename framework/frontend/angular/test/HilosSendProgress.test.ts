// Angular lifecycle for the shared SendProgress scenarios.
import { TestBed } from '@angular/core/testing'
import { describe, expect, it, vi } from 'vitest'

import {
  registerSendProgressCases,
  type SendProgressInput,
  type SendProgressView,
} from '../../test-support/sendProgressCases.js'
import { HilosSendProgress } from '../src/HilosSendProgress.js'

/** Mount the real component and drive its inputs through Angular. */
async function mountSendProgress(
  input: SendProgressInput,
): Promise<SendProgressView> {
  const fixture = TestBed.createComponent(HilosSendProgress)
  const element: HTMLElement = fixture.nativeElement
  try {
    let current = input
    const subscription = fixture.componentInstance.sendAgain.subscribe(() =>
      current.onSendAgain(),
    )
    fixture.componentRef.setInput('progress', input.progress)
    fixture.componentRef.setInput('to', input.to)
    fixture.componentRef.setInput('resendAt', input.resendAt)
    fixture.componentRef.setInput('busy', input.busy)
    fixture.componentRef.setInput('dataId', input.dataId)
    fixture.detectChanges()
    return {
      root: document.body,
      async update(patch) {
        current = { ...current, ...patch }
        for (const [key, value] of Object.entries(patch)) {
          if (key !== 'onSendAgain') {
            fixture.componentRef.setInput(key, value)
          }
        }
        fixture.detectChanges()
      },
      async click(target) {
        target.click()
        fixture.detectChanges()
      },
      async flush(change) {
        change?.()
        fixture.detectChanges()
        await fixture.whenStable()
      },
      async unmount() {
        try {
          subscription.unsubscribe()
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

registerSendProgressCases(
  'Angular',
  { describe, expect, it, vi },
  mountSendProgress,
)
