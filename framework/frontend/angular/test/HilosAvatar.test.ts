// Angular input, event and lifecycle delivery for the shared avatar cases.
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import {
  registerAvatarCases,
  type WithAvatar,
} from '../../test-support/avatarCases.js'
import { HilosAvatar } from '../src/HilosAvatar.js'

/** Reset TestBed even if component creation or an input update fails. */
const withAvatar: WithAvatar = async (input, steps) => {
  let element: HTMLElement | undefined
  try {
    const fixture = TestBed.createComponent(HilosAvatar)
    element = fixture.nativeElement
    for (const [key, value] of Object.entries(input)) {
      fixture.componentRef.setInput(key, value)
    }
    fixture.detectChanges()
    await steps({
      root: element!,
      async update(patch) {
        for (const [key, value] of Object.entries(patch)) {
          fixture.componentRef.setInput(key, value)
        }
        fixture.detectChanges()
      },
      async imageError(image) {
        image.dispatchEvent(new Event('error'))
        fixture.detectChanges()
      },
    })
  } finally {
    try {
      TestBed.resetTestingModule()
    } finally {
      element?.remove()
    }
  }
}

registerAvatarCases('Angular', { describe, it, expect }, withAvatar)
