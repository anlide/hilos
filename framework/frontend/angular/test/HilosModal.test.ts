// The Angular peer of vue/src/HilosModal.test.ts and
// react/test/HilosModal.test.tsx: these cases pin the shared scroll lock at the
// component boundary, where distinct modal instances become distinct owners,
// the modal layer each of those instances stands on, and the landing place of
// focus when a dialog opens.
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
        <hilos-modal
          [open]="nestedOpen()"
          [confirmOnClose]="nestedConfirmOnClose()"
        />
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
  readonly nestedConfirmOnClose = signal(false)
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

/** A guarded modal whose width the opening surface can choose. */
@Component({
  selector: 'test-modal-size-host',
  imports: [HilosModal],
  template: `
    <hilos-modal [open]="true" [confirmOnClose]="true" [size]="size()" />
  `,
})
class SizeHost {
  readonly size = signal<'' | 'wide'>('')
}

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

/**
 * Open the modal nested in the first one, the way a details panel opens: after
 * the modal it stands in is already up.
 *
 * @param fixture The mounted host.
 */
function openNested(fixture: ComponentFixture<ModalHost>): void {
  fixture.componentInstance.nested.set(true)
  fixture.componentInstance.nestedOpen.set(true)
  fixture.detectChanges()
}

/**
 * The modal layer depths of every element a selector matches, in DOM order.
 *
 * @param selector The elements to read, each of which must be a modal layer.
 * @returns The value of `--hilos-modal-depth` on each of them.
 */
function layerDepths(selector: string): string[] {
  return [...document.querySelectorAll<HTMLElement>(selector)].map(
    (element) => {
      expect(element.classList.contains('hilos-modal-layer')).toBe(true)
      return element.style.getPropertyValue('--hilos-modal-depth')
    },
  )
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

  it('stands a lone modal on layer 0, backdrop and dialog alike', () => {
    mountHost()

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(layerDepths('[data-id="modal"]')).toEqual(['0'])
  })

  it('stands a modal opened inside an open modal on layer 1', () => {
    const fixture = mountHost()

    openNested(fixture)

    expect(layerDepths('.modal-backdrop')).toEqual(['0', '1'])
    expect(layerDepths('[data-id="modal"]')).toEqual(['0', '1'])
  })

  it('puts the confirm step on the layer of its own modal', () => {
    const fixture = mountHost()
    fixture.componentInstance.nestedConfirmOnClose.set(true)
    openNested(fixture)

    document
      .querySelectorAll<HTMLButtonElement>('[data-id="modal-close"]')[1]
      ?.click()
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['1'])
  })

  it('stands wide when the surface asks for it, and keeps the confirm step narrow', () => {
    const fixture = TestBed.createComponent(SizeHost)
    fixture.detectChanges()
    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    fixture.detectChanges()

    let dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs).toHaveLength(2)
    dialogs.forEach((dialog) => {
      expect(dialog.classList.contains('modal-lg')).toBe(false)
    })

    fixture.componentInstance.size.set('wide')
    fixture.detectChanges()
    dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs[0]?.classList.contains('modal-lg')).toBe(true)
    expect(dialogs[1]?.classList.contains('modal-lg')).toBe(false)
  })

  it('opens the next modal over a lone one on layer 1 again after the upper closed', () => {
    const fixture = mountHost()
    fixture.componentInstance.secondPresent.set(true)
    fixture.detectChanges()
    fixture.componentInstance.secondOpen.set(false)
    fixture.detectChanges()

    fixture.componentInstance.secondOpen.set(true)
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal"]')).toEqual(['0', '1'])
  })

  it('closes only the upper layer on Escape pressed in it', () => {
    const fixture = mountHost()
    openNested(fixture)

    document.querySelectorAll('[data-id="modal"]')[1]?.dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Escape',
        bubbles: true,
        cancelable: true,
      }),
    )
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal"]')).toEqual(['0'])
  })

  it('keeps Tab in the upper layer that ends the layer below', () => {
    const fixture = mountHost()
    openNested(fixture)
    // The nested modal is the last thing in a modal with no footer, so its
    // only button is the last focusable element of both traps.
    const upperClose = document.querySelectorAll<HTMLElement>(
      '[data-id="modal-close"]',
    )[1]
    upperClose.focus()

    upperClose.dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Tab',
        bubbles: true,
        cancelable: true,
      }),
    )

    expect(document.activeElement).toBe(upperClose)
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
