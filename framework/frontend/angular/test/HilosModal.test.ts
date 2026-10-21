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
    <hilos-modal [open]="firstOpen()" [confirmOnClose]="firstConfirmOnClose()">
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
  readonly firstConfirmOnClose = signal(false)
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

/**
 * A host that names the dialog through the attribute inputs, with no title of
 * its own.
 */
@Component({
  selector: 'test-modal-aria-name-host',
  imports: [HilosModal],
  template: `
    <hilos-modal
      [open]="true"
      [aria-label]="'Sign in'"
      [aria-labelledby]="'body-heading'"
    />
  `,
})
class AriaNameHost {}

/** A host whose visible title names the dialog, beside a labelledby input. */
@Component({
  selector: 'test-modal-titled-name-host',
  imports: [HilosModal],
  template: `
    <hilos-modal
      [open]="true"
      [title]="'Edit'"
      [aria-labelledby]="'body-heading'"
    />
  `,
})
class TitledNameHost {}

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

/** A guarded modal with actions declared in its footer. */
@Component({
  selector: 'test-modal-footers-host',
  imports: [HilosModal],
  template: `
    <hilos-modal [open]="true" [confirmOnClose]="true">
      <ng-template #modalActions>
        <button type="button">Save</button>
      </ng-template>
    </hilos-modal>
  `,
})
class FootersHost {}

/**
 * A modal whose busy flag, open flag and Escape/backdrop options the test
 * flips in place on the mounted component, with a footer that takes the issued
 * `requestClose`. The no-input case is the other hosts, which bind no `busy`.
 */
@Component({
  selector: 'test-modal-busy-host',
  imports: [HilosModal],
  template: `
    <hilos-modal
      [open]="open()"
      [confirmOnClose]="confirmOnClose()"
      [closeOnEsc]="closeOnEsc()"
      [closeOnBackdrop]="closeOnBackdrop()"
      [busy]="busy()"
      (cancel)="cancels.set(cancels() + 1)"
    >
      <input data-autofocus data-id="field" />
      <ng-template #modalActions let-requestClose="requestClose">
        <button type="button" data-id="footer-cancel" (click)="requestClose()">
          Cancel
        </button>
        <button type="button" data-id="footer-save">Save</button>
      </ng-template>
    </hilos-modal>
  `,
})
class BusyHost {
  readonly open = signal(true)
  readonly confirmOnClose = signal(false)
  readonly closeOnEsc = signal(true)
  readonly closeOnBackdrop = signal(true)
  readonly busy = signal(false)
  readonly cancels = signal(0)
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

/** The header close button of the open dialog. */
function closeButton(): HTMLButtonElement | null {
  return document.querySelector<HTMLButtonElement>('[data-id="modal-close"]')
}

/**
 * One element by its `data-id`.
 *
 * @param id The data-id to look up.
 * @returns The element, or null when it is not drawn.
 */
function byId(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/**
 * Press a key on an element the way the browser does.
 *
 * @param target The element that has focus.
 * @param key The key name.
 * @param shiftKey Whether Shift is held.
 */
function press(target: Element, key: string, shiftKey = false): void {
  target.dispatchEvent(
    new KeyboardEvent('keydown', {
      key,
      shiftKey,
      bubbles: true,
      cancelable: true,
    }),
  )
}

/**
 * Mount a busy host and flip the modal's `busy` input in place.
 *
 * @returns The fixture, the host, and a setter for the modal's busy input.
 */
function mountBusyHost(): {
  fixture: ComponentFixture<BusyHost>
  host: BusyHost
  setBusy: (busy: boolean) => void
} {
  const fixture = TestBed.createComponent(BusyHost)
  fixture.detectChanges()

  return {
    fixture,
    host: fixture.componentInstance,
    setBusy: (busy) => {
      fixture.componentInstance.busy.set(busy)
      fixture.detectChanges()
    },
  }
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

  it('stands the confirm step one layer above its own modal', () => {
    const fixture = mountHost()
    fixture.componentInstance.nestedConfirmOnClose.set(true)
    openNested(fixture)

    document
      .querySelectorAll<HTMLButtonElement>('[data-id="modal-close"]')[1]
      ?.click()
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['2'])
    expect(layerDepths('.modal-backdrop')).toEqual(['0', '1', '2'])
  })

  it('stands the confirm step on layer 1 over a lone modal', () => {
    const fixture = mountHost()
    fixture.componentInstance.firstConfirmOnClose.set(true)
    fixture.detectChanges()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['1'])
    expect(layerDepths('.modal-backdrop')).toEqual(['0', '1'])
  })

  it('releases the confirm step layer on keep editing', () => {
    const fixture = mountHost()
    fixture.componentInstance.firstConfirmOnClose.set(true)
    fixture.detectChanges()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    fixture.detectChanges()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-cancel"]')
      ?.click()
    fixture.detectChanges()

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    fixture.componentInstance.secondPresent.set(true)
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal"]')).toEqual(['0', '1'])
  })

  it('releases the confirm step layer on discard', () => {
    const fixture = mountHost()
    fixture.componentInstance.firstConfirmOnClose.set(true)
    fixture.detectChanges()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    fixture.detectChanges()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-confirm-discard"]')
      ?.click()
    fixture.detectChanges()

    expect(layerDepths('.modal-backdrop')).toEqual([])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    fixture.componentInstance.secondPresent.set(true)
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal"]')).toEqual(['0'])
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

  it('names the dialog from aria-labelledby and keeps aria-label off the host tag', () => {
    const fixture = TestBed.createComponent(AriaNameHost)
    fixture.detectChanges()
    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-labelledby')).toBe('body-heading')
    expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
    expect(
      fixture.nativeElement
        .querySelector('hilos-modal')
        ?.hasAttribute('aria-label'),
    ).toBe(false)
  })

  it('drops aria-labelledby when the dialog has a visible title', () => {
    const fixture = TestBed.createComponent(TitledNameHost)
    fixture.detectChanges()
    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-labelledby')).toBeNull()
    expect(dialog?.getAttribute('aria-label')).toBe('Edit')
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

  it('shapes both footers with hilos-button-row and no column reverse', () => {
    const fixture = TestBed.createComponent(FootersHost)
    fixture.detectChanges()

    document
      .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
      ?.click()
    fixture.detectChanges()

    const footers = document.querySelectorAll('.modal-footer')
    expect(footers).toHaveLength(2)
    footers.forEach((footer) => {
      expect(footer.classList.contains('hilos-button-row')).toBe(true)
      expect(footer.classList.contains('flex-column-reverse')).toBe(false)
    })
  })

  it('is not busy without the input: the close button is enabled', () => {
    const fixture = mountHost()

    expect(closeButton()?.disabled).toBe(false)
    closeButton()?.click()
    fixture.detectChanges()

    expect(layerDepths('[data-id="modal"]')).toEqual([])
  })

  it('refuses every close gesture while busy and closes again once idle', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    host.confirmOnClose.set(true)
    setBusy(true)

    const close = closeButton()
    expect(close?.disabled).toBe(true)
    expect(close?.classList.contains('btn-close')).toBe(true)
    expect(close?.getAttribute('aria-label')).toBe('Close')

    close?.click()
    press(byId('modal') as Element, 'Escape')
    ;(byId('modal') as HTMLElement).click()
    byId('footer-cancel')?.click()
    fixture.detectChanges()

    expect(host.cancels()).toBe(0)
    expect(byId('modal-confirm')).toBeNull()
    expect(byId('modal')).not.toBeNull()

    setBusy(false)
    expect(closeButton()?.disabled).toBe(false)

    // Idle, the dirty draft goes through the same Discard changes? step.
    closeButton()?.click()
    fixture.detectChanges()
    expect(byId('modal-confirm')).not.toBeNull()
    expect(host.cancels()).toBe(0)

    byId('modal-confirm-discard')?.click()
    fixture.detectChanges()
    expect(host.cancels()).toBe(1)
  })

  it('closes on the close button again once busy clears, replaying nothing', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    setBusy(true)
    closeButton()?.click()
    expect(host.cancels()).toBe(0)

    setBusy(false)
    expect(host.cancels()).toBe(0)

    closeButton()?.click()
    fixture.detectChanges()
    expect(host.cancels()).toBe(1)
  })

  it('outranks closeOnEsc and closeOnBackdrop while busy', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    setBusy(true)
    press(byId('modal') as Element, 'Escape')
    ;(byId('modal') as HTMLElement).click()
    expect(host.cancels()).toBe(0)

    setBusy(false)
    press(byId('modal') as Element, 'Escape')
    fixture.detectChanges()
    expect(host.cancels()).toBe(1)
  })

  it('keeps the draft, the focus and an open confirm step when busy flips', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    host.confirmOnClose.set(true)
    fixture.detectChanges()
    const field = byId('field') as HTMLInputElement
    field.value = 'draft'

    setBusy(true)
    expect(document.activeElement).toBe(field)
    setBusy(false)
    expect(document.activeElement).toBe(field)
    expect((byId('field') as HTMLInputElement).value).toBe('draft')

    closeButton()?.click()
    fixture.detectChanges()
    const confirm = byId('modal-confirm')
    expect(document.activeElement).toBe(confirm)

    setBusy(true)
    press(confirm as Element, 'Escape')
    byId('modal-confirm-discard')?.click()
    fixture.detectChanges()

    expect(byId('modal-confirm')).toBe(confirm)
    expect(document.activeElement).toBe(confirm)
    expect((byId('field') as HTMLInputElement).value).toBe('draft')
    expect(host.cancels()).toBe(0)
  })

  it('does not reactivate scroll lock or layer when busy flips', () => {
    const { host, setBusy } = mountBusyHost()
    const dialog = byId('modal')
    const depth = layerDepths('[data-id="modal"]')

    setBusy(true)
    setBusy(false)

    expect(byId('modal')).toBe(dialog)
    expect(layerDepths('[data-id="modal"]')).toEqual(depth)
    expect(document.body.classList.contains('modal-open')).toBe(true)
    expect(host.open()).toBe(true)
  })

  it('leaves the disabled close button out of the Tab cycle', () => {
    const { setBusy } = mountBusyHost()
    const field = byId('field') as HTMLElement
    const save = byId('footer-save') as HTMLElement

    save.focus()
    press(save, 'Tab')
    expect(document.activeElement).toBe(closeButton())

    setBusy(true)
    save.focus()
    press(save, 'Tab')
    expect(document.activeElement).toBe(field)

    press(field, 'Tab', true)
    expect(document.activeElement).toBe(save)
  })

  it('closes when the parent sets it closed while busy, releasing scroll and focus', () => {
    const opener = document.createElement('button')
    document.body.appendChild(opener)
    opener.focus()

    const { fixture, host, setBusy } = mountBusyHost()
    setBusy(true)
    expect(document.body.classList.contains('modal-open')).toBe(true)

    host.open.set(false)
    fixture.detectChanges()

    expect(byId('modal')).toBeNull()
    expect(document.querySelector('.modal-backdrop')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(document.activeElement).toBe(opener)
    opener.remove()
  })
})
