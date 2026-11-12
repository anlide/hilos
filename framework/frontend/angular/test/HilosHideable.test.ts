// Angular projection and lifecycle for the shared Hideable scenarios.
import { Component } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import {
  registerHideableCases,
  type HideableInput,
  type HideableView,
} from '../../test-support/hideableCases.js'
import { HilosHideable } from '../src/HilosHideable.js'

/** Give the real component either a projected template or its text fallback. */
@Component({
  selector: 'test-hideable-host',
  imports: [HilosHideable],
  template: `
    @if (renderValue) {
      <hilos-hideable [value]="value">
        <ng-template let-value>
          <code data-id="shown">{{ renderValue(value) }}</code>
        </ng-template>
      </hilos-hideable>
    } @else {
      <hilos-hideable [value]="value" />
    }
  `,
})
class HideableHost {
  value: HideableInput['value'] = null
  renderValue: HideableInput['renderValue']
}

/** Mount and destroy the real component through its projected-template host. */
async function mountHideable(input: HideableInput): Promise<HideableView> {
  const fixture = TestBed.createComponent(HideableHost)
  const element: HTMLElement = fixture.nativeElement
  try {
    fixture.componentInstance.value = input.value
    fixture.componentInstance.renderValue = input.renderValue
    fixture.detectChanges()
    return {
      root: element,
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

registerHideableCases('Angular', { describe, it, expect }, mountHideable)
