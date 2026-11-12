// Angular mounting and cleanup for the shared QR drawing contract.
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import {
  registerQrCodeCases,
  type WithQrCode,
} from '../../test-support/qrCodeCases.js'
import { HilosQrCode } from '../src/HilosQrCode.js'

/** Reset TestBed even if creation or the first change detection fails. */
const withQrCode: WithQrCode = async (input, steps) => {
  let element: HTMLElement | undefined
  try {
    const fixture = TestBed.createComponent(HilosQrCode)
    element = fixture.nativeElement
    fixture.componentRef.setInput('text', input.text)
    fixture.componentRef.setInput('label', input.label)
    fixture.detectChanges()
    steps(element!)
  } finally {
    try {
      TestBed.resetTestingModule()
    } finally {
      element?.remove()
    }
  }
}

registerQrCodeCases('Angular', { describe, it, expect }, withQrCode)
