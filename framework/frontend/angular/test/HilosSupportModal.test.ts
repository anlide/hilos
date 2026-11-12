// Angular input, event and lifecycle delivery for the shared support dialog cases.
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import {
  registerSupportModalCases,
  type WithSupportModal,
} from '../../test-support/supportModalCases.js'
import { HilosSupportModal } from '../src/public/HilosSupportModal.js'

/** Reset TestBed even when creation, detection, an input or a step throws. */
const withSupportModal: WithSupportModal = async (open, steps) => {
  const originalNodes = new Set(document.body.childNodes)
  const modalOpen = document.body.classList.contains('modal-open')
  const previousFocus = document.activeElement
  try {
    const fixture = TestBed.createComponent(HilosSupportModal)
    fixture.componentRef.setInput('open', open)
    fixture.componentInstance.openChange.subscribe((value) => {
      fixture.componentRef.setInput('open', value)
    })
    fixture.detectChanges()
    await fixture.whenStable()
    await steps({
      root: fixture.nativeElement as HTMLElement,
      async update(value) {
        fixture.componentRef.setInput('open', value)
        fixture.detectChanges()
        await fixture.whenStable()
      },
      async click(target) {
        target.click()
        fixture.detectChanges()
        await fixture.whenStable()
      },
    })
  } finally {
    try {
      TestBed.resetTestingModule()
    } finally {
      for (const node of [...document.body.childNodes]) {
        if (!originalNodes.has(node)) node.remove()
      }
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus()
      }
    }
  }
}

registerSupportModalCases('Angular', { describe, it, expect }, withSupportModal)
