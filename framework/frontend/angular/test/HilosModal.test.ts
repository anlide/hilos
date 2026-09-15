// The Angular peer of vue/src/HilosModal.test.ts and
// react/test/HilosModal.test.tsx: these cases pin the shared scroll lock at the
// component boundary, where distinct modal instances become distinct owners,
// and the landing place of focus when a dialog opens.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { HilosModal } from '../src/HilosModal.js'

/** A host that can draw either nested modals or two sibling modals. */
@Component({
  selector: 'test-modal-host',
  imports: [HilosModal],
  template: `
    <hilos-modal [open]="firstOpen()">
      @if (nested()) {
        <hilos-modal [open]="nestedOpen()" />
      }
    </hilos-modal>
    @if (secondPresent()) {
      <hilos-modal [open]="secondOpen()" />
    }
  `,
})
class ModalHost {
  readonly firstOpen = signal(true)
  readonly nested = signal(false)
  readonly nestedOpen = signal(false)
  readonly secondPresent = signal(false)
  readonly secondOpen = signal(true)
}

/** A host whose body carries a `[data-autofocus]` mark. */
@Component({
  selector: 'test-modal-marked-host',
  imports: [HilosModal],
  template: `
    <hilos-modal [open]="true">
      <input data-autofocus data-id="field" />
    </hilos-modal>
  `,
})
class MarkedHost {}

/** A host that declares focus on the dialog itself. */
@Component({
  selector: 'test-modal-dialog-focus-host',
  imports: [HilosModal],
  template: ` <hilos-modal [open]="true" initialFocus="dialog" /> `,
})
class DialogFocusHost {}

/** A host whose close is guarded, so the discard-confirm step can appear. */
@Component({
  selector: 'test-modal-confirm-focus-host',
  imports: [HilosModal],
  template: ` <hilos-modal [open]="true" [confirmOnClose]="true" /> `,
})
class ConfirmFocusHost {}

/**
 * Mount the host and render its modal effects.
 *
 * @returns The mounted fixture, already rendered once.
 */
function mountHost(): ComponentFixture<ModalHost> {
  const fixture = TestBed.createComponent(ModalHost)
  fixture.detectChanges()

  return fixture
}

describe('HilosModal', () => {
  it('keeps scroll locked when an open modal contains a closed modal', () => {
    const fixture = mountHost()
    fixture.componentInstance.nested.set(true)
    fixture.detectChanges()

    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('keeps scroll locked when one of two open modals closes', () => {
    const fixture = mountHost()
    fixture.componentInstance.secondPresent.set(true)
    fixture.detectChanges()

    fixture.componentInstance.firstOpen.set(false)
    fixture.detectChanges()

    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('focuses a marked child when the dialog opens', () => {
    const fixture = TestBed.createComponent(MarkedHost)
    fixture.detectChanges()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="field"]'),
    )
  })

  it('focuses the dialog when initialFocus is dialog', () => {
    const fixture = TestBed.createComponent(DialogFocusHost)
    fixture.detectChanges()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="modal"]'),
    )
  })

  it('focuses the confirm dialog, not Discard, on the discard-confirm step', () => {
    const fixture = TestBed.createComponent(ConfirmFocusHost)
    fixture.detectChanges()
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    fixture.detectChanges()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="modal-confirm"]'),
    )
    expect(document.activeElement).not.toBe(
      document.querySelector('[data-id="modal-confirm-discard"]'),
    )
  })
})
