// The Angular peer of vue/src/HilosModal.test.ts and
// react/test/HilosModal.test.tsx: these cases pin the shared scroll lock at the
// component boundary, where distinct modal instances become distinct owners,
// the modal layer each of those instances stands on, and the landing place of
// focus when a dialog opens.
import { Component, signal } from '@angular/core'
import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  CONNECTION_INTERACTION_COPY,
  FocusTrap,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  type ConnectionState,
  type HilosConnection,
  type ModalController,
} from '@hilos/core/base'

import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { LoadingButton } from '../src/LoadingButton.js'

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
      [initialFocus]="initialFocus()"
      [copyText]="copyText()"
      (cancel)="cancels.set(cancels() + 1)"
    >
      <form data-id="draft-form">
        <input data-autofocus data-id="field" />
        <select data-id="choice">
          <option value="a">A</option>
          <option value="b">B</option>
        </select>
        <textarea data-id="notes"></textarea>
        <button type="button" data-id="body-button">Choose</button>
        <input data-id="independent-field" [disabled]="disabled()" />
      </form>
      <ng-template #modalActions let-requestClose="requestClose">
        <button type="button" data-id="footer-cancel" (click)="requestClose()">
          Cancel
        </button>
        <button type="button" data-id="footer-save">Save</button>
      </ng-template>
    </hilos-modal>
    @if (upperPresent()) {
      <hilos-modal [open]="true" title="Details" initialFocus="dialog" />
    }
  `,
})
class BusyHost {
  readonly open = signal(true)
  readonly confirmOnClose = signal(false)
  readonly closeOnEsc = signal(true)
  readonly closeOnBackdrop = signal(true)
  readonly busy = signal(false)
  readonly disabled = signal(false)
  readonly initialFocus = signal<'' | 'dialog' | 'inner'>('')
  readonly copyText = signal('')
  readonly cancels = signal(0)
  readonly upperPresent = signal(false)
}

/** A custom body owns permanent field groups, keeping its sender outside them. */
@Component({
  selector: 'test-modal-custom-body',
  imports: [HilosLayout, HilosModal, LoadingButton],
  template: `
    <hilos-layout [connection]="connection()">
      <hilos-modal [open]="true" [busy]="busy()" title="Edit">
        <ng-template #modalBody let-busy="busy">
          <form
            data-id="custom-form"
            (submit)="send($event)"
            (click)="clicked()"
          >
            <fieldset [disabled]="busy" data-id="custom-fields">
              <input data-autofocus data-id="custom-field" />
              <select data-id="custom-choice">
                <option value="a">A</option>
                <option value="b">B</option>
              </select>
            </fieldset>
            <fieldset [disabled]="busy" data-id="custom-notes-group">
              <textarea data-id="custom-notes"></textarea>
              <button type="button" data-id="custom-action">Choose</button>
            </fieldset>
            <button
              hilosLoadingButton
              type="submit"
              data-id="custom-send"
              [loading]="busy"
              [disabled]="disabled()"
              (click)="senderClicked()"
            >
              Save
            </button>
          </form>
        </ng-template>
      </hilos-modal>
    </hilos-layout>
  `,
})
class CustomBodyHost {
  readonly connection = signal(modalFeed().connection)
  readonly busy = signal(false)
  readonly disabled = signal(false)
  readonly clicked = vi.fn()
  readonly senderClicked = vi.fn()
  readonly sent = vi.fn()

  /** Send once, including when a second submit precedes the next render. */
  send(event: Event): void {
    event.preventDefault()
    if (this.busy()) return
    this.busy.set(true)
    this.sent()
  }
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

  it('leaves the disabled close button and busy body out of the Tab cycle', () => {
    const { setBusy } = mountBusyHost()
    const field = byId('field') as HTMLElement
    const save = byId('footer-save') as HTMLElement
    const cancel = byId('footer-cancel') as HTMLElement

    save.focus()
    press(save, 'Tab')
    expect(document.activeElement).toBe(closeButton())

    setBusy(true)
    save.focus()
    press(save, 'Tab')
    expect(document.activeElement).toBe(cancel)

    press(cancel, 'Tab', true)
    expect(document.activeElement).toBe(save)

    setBusy(false)
    closeButton()?.focus()
    press(closeButton()!, 'Tab', true)
    expect(document.activeElement).toBe(save)
    field.focus()
    expect(document.activeElement).toBe(field)
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

describe('HilosModal busy body', () => {
  const realClipboard = navigator.clipboard

  afterEach(() => {
    vi.restoreAllMocks()
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: realClipboard,
    })
  })

  it('disables the same default fields and form without losing their draft or independent disabled', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    const form = modalElement('draft-form')
    const fieldset = form.parentElement as HTMLFieldSetElement
    const field = modalElement('field') as HTMLInputElement
    const choice = modalElement('choice') as HTMLSelectElement
    const notes = modalElement('notes') as HTMLTextAreaElement
    const button = modalElement('body-button') as HTMLButtonElement
    const independent = modalElement('independent-field') as HTMLInputElement
    const fields = [field, choice, notes, button]
    host.disabled.set(true)
    fixture.detectChanges()
    expect(fieldset.tagName).toBe('FIELDSET')
    expect(fieldset.disabled).toBe(false)
    expect(fields.map((element) => element.matches(':disabled'))).toEqual([
      false,
      false,
      false,
      false,
    ])
    field.value = 'unfinished name'
    field.setSelectionRange(2, 8)
    choice.value = 'b'
    notes.value = 'unfinished notes'

    for (const busy of [true, false]) {
      setBusy(busy)
      expect(modalElement('draft-form')).toBe(form)
      expect(form.parentElement).toBe(fieldset)
      expect(fieldset.disabled).toBe(busy)
      expect(fields.map((element) => element.matches(':disabled'))).toEqual([
        busy,
        busy,
        busy,
        busy,
      ])
      expect(modalElement('field')).toBe(field)
      expect(modalElement('choice')).toBe(choice)
      expect(modalElement('notes')).toBe(notes)
      expect(modalElement('body-button')).toBe(button)
      expect(field.value).toBe('unfinished name')
      expect([field.selectionStart, field.selectionEnd]).toEqual([2, 8])
      expect(choice.value).toBe('b')
      expect(notes.value).toBe('unfinished notes')
      expect(independent.disabled).toBe(true)
      expect(independent.matches(':disabled')).toBe(true)
      expect(modalElement('footer-save').matches(':disabled')).toBe(false)
      expect(field.closest('[inert], [aria-hidden="true"]')).toBeNull()
    }
    host.disabled.set(false)
    fixture.detectChanges()
    expect(independent.matches(':disabled')).toBe(false)
  })

  it('opens busy on the dialog, skipping the disabled mark until the reply', () => {
    const fixture = TestBed.createComponent(BusyHost)
    fixture.componentInstance.busy.set(true)
    fixture.detectChanges()
    const dialog = modalElement('modal')
    const field = modalElement('field')
    expect(field.matches(':disabled')).toBe(true)
    expect(document.activeElement).toBe(dialog)
    fixture.componentInstance.busy.set(false)
    fixture.detectChanges()
    expect(field.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(field)
  })

  it('disables Copy and checks live busy before the rendered button catches up', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    })
    const fixture = TestBed.createComponent(HilosModal)
    fixture.componentRef.setInput('open', true)
    fixture.componentRef.setInput('copyText', 'technical details')
    fixture.detectChanges()
    const copy = modalElement('modal-copy') as HTMLButtonElement
    expect(copy.disabled).toBe(false)

    fixture.componentRef.setInput('busy', true)
    copy.click()
    expect(writeText).not.toHaveBeenCalled()
    fixture.detectChanges()
    expect(copy.disabled).toBe(true)
    copy.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(writeText).not.toHaveBeenCalled()

    fixture.componentRef.setInput('busy', false)
    fixture.detectChanges()
    expect(modalElement('modal-copy')).toBe(copy)
    expect(copy.disabled).toBe(false)
    expect(writeText).not.toHaveBeenCalled()
    copy.click()
    await fixture.whenStable()
    fixture.detectChanges()
    expect(writeText).toHaveBeenCalledExactlyOnceWith('technical details')
    expect(copy.textContent?.trim()).toBe('Copied')
  })

  it.each(['dialog', 'body', 'none'] as const)(
    'restores initial focus from %s only after busy clears and fields are enabled',
    (from) => {
      const { fixture, setBusy } = mountBusyHost()
      const field = modalElement('field')
      const dialog = modalElement('modal')
      const refocus = vi.spyOn(FocusTrap.prototype, 'refocus')
      setBusy(true)
      expect(refocus).not.toHaveBeenCalled()
      dialog.focus()
      if (from !== 'dialog') dialog.blur()
      const active =
        from === 'none'
          ? vi.spyOn(document, 'activeElement', 'get').mockReturnValue(null)
          : undefined

      setBusy(false)
      active?.mockRestore()
      expect(field.matches(':disabled')).toBe(false)
      expect(document.activeElement).toBe(field)
      expect(refocus).toHaveBeenCalledExactlyOnceWith(dialog, 'marked')
      refocus.mockClear()
      dialog.focus()
      fixture.detectChanges()
      expect(refocus).not.toHaveBeenCalled()
      expect(document.activeElement).toBe(dialog)
    },
  )

  it.each(['dialog', 'inner'] as const)(
    'honors the %s initial focus placement after busy',
    (placement) => {
      const { host, setBusy } = mountBusyHost()
      host.initialFocus.set(placement)
      setBusy(true)
      const dialog = modalElement('modal')
      dialog.focus()
      setBusy(false)
      expect(document.activeElement).toBe(
        placement === 'dialog' ? dialog : modalElement('field'),
      )
    },
  )

  it('keeps another control or a higher dialog focused when busy clears', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    const dialog = modalElement('modal')
    setBusy(true)
    const save = modalElement('footer-save')
    save.focus()
    setBusy(false)
    expect(document.activeElement).toBe(save)

    setBusy(true)
    host.upperPresent.set(true)
    fixture.detectChanges()
    const upperDialog = document.querySelector('[aria-label="Details"]')
    expect(dialog.isConnected).toBe(true)
    expect(document.activeElement).toBe(upperDialog)
    setBusy(false)
    expect(document.activeElement).toBe(upperDialog)
    expect(modalElement('modal')).toBe(dialog)
    expect(layerDepths('[data-id="modal"]')).toEqual(['0', '1'])
  })

  it('does not refocus an idle modal or an open confirm when unrelated state changes', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    const dialog = modalElement('modal')
    dialog.focus()
    const refocus = vi.spyOn(FocusTrap.prototype, 'refocus')
    host.disabled.set(true)
    fixture.detectChanges()
    expect(document.activeElement).toBe(dialog)
    expect(refocus).not.toHaveBeenCalled()

    host.confirmOnClose.set(true)
    fixture.detectChanges()
    closeButton()?.click()
    fixture.detectChanges()
    const confirm = modalElement('modal-confirm')
    setBusy(true)
    refocus.mockClear()
    setBusy(false)
    expect(modalElement('modal-confirm')).toBe(confirm)
    expect(document.activeElement).toBe(confirm)
    expect(refocus).not.toHaveBeenCalled()

    setBusy(true)
    confirm.blur()
    expect(document.activeElement).toBe(document.body)
    setBusy(false)
    expect(document.activeElement).toBe(document.body)
    expect(refocus).not.toHaveBeenCalled()
  })

  it('does not restore focus after close, detachment, destruction or a renewed busy', () => {
    const { fixture, host, setBusy } = mountBusyHost()
    const refocus = vi.spyOn(FocusTrap.prototype, 'refocus')
    setBusy(true)
    modalElement('modal').focus()
    host.busy.set(false)
    host.busy.set(true)
    fixture.detectChanges()
    expect(refocus).not.toHaveBeenCalled()
    const root = modalElement('modal')
    root.remove()
    setBusy(false)
    expect(refocus).not.toHaveBeenCalled()
    fixture.destroy()

    const next = mountBusyHost()
    next.setBusy(true)
    refocus.mockClear()
    next.host.open.set(false)
    next.setBusy(false)
    expect(refocus).not.toHaveBeenCalled()
    expect(byId('modal')).toBeNull()
    next.host.open.set(true)
    next.setBusy(true)
    refocus.mockClear()
    next.host.busy.set(false)
    next.fixture.destroy()
    TestBed.tick()
    expect(refocus).not.toHaveBeenCalled()
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })
})

describe('HilosModal custom busy body', () => {
  afterEach(() => {
    TestBed.resetTestingModule()
    vi.useRealTimers()
  })

  it('projects busy into persistent groups while its sender locks immediately and spins at 300 ms', () => {
    vi.useFakeTimers()
    const fixture = TestBed.createComponent(CustomBodyHost)
    const host = fixture.componentInstance
    fixture.detectChanges()
    const form = modalElement('custom-form') as HTMLFormElement
    const field = modalElement('custom-field') as HTMLInputElement
    const choice = modalElement('custom-choice') as HTMLSelectElement
    const notes = modalElement('custom-notes') as HTMLTextAreaElement
    const action = modalElement('custom-action')
    const groups = [
      modalElement('custom-fields'),
      modalElement('custom-notes-group'),
    ]
    const sender = modalElement('custom-send') as HTMLButtonElement
    const fields = [field, choice, notes, action]
    expect(form.parentElement?.tagName).toBe('DIV')
    expect(groups.every((group) => !group.contains(sender))).toBe(true)
    field.value = 'custom draft'
    choice.value = 'b'
    notes.value = 'custom notes'
    sender.focus()
    sender.click()
    // A second submit before the render is held by the form's immediate guard.
    form.requestSubmit(sender)
    expect(host.sent).toHaveBeenCalledTimes(1)
    fixture.detectChanges()

    for (const elapsed of [0, 299, 1]) {
      vi.advanceTimersByTime(elapsed)
      fixture.detectChanges()
      expect(fields.every((element) => element.matches(':disabled'))).toBe(true)
      expect(sender.matches(':disabled')).toBe(false)
      expect(sender.disabled).toBe(false)
      expect(sender.getAttribute('aria-busy')).toBe('true')
      expect(sender.getAttribute('aria-disabled')).toBe('true')
      expect(document.activeElement).toBe(sender)
      const spinning = elapsed === 1
      expect(sender.querySelector('[role="status"]') !== null).toBe(spinning)
      expect(sender.firstElementChild?.classList.contains('invisible')).toBe(
        spinning,
      )
      expect(sender.classList.contains('disabled')).toBe(spinning)
      const repeat = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
      })
      sender.dispatchEvent(repeat)
      expect(repeat.defaultPrevented).toBe(true)
      expect(host.senderClicked).toHaveBeenCalledTimes(1)
      expect(host.clicked).toHaveBeenCalledTimes(1)
      expect(host.sent).toHaveBeenCalledTimes(1)
    }

    host.busy.set(false)
    fixture.detectChanges()
    expect(modalElement('custom-form')).toBe(form)
    expect(modalElement('custom-fields')).toBe(groups[0])
    expect(modalElement('custom-notes-group')).toBe(groups[1])
    expect(modalElement('custom-field')).toBe(field)
    expect(modalElement('custom-choice')).toBe(choice)
    expect(modalElement('custom-notes')).toBe(notes)
    expect(modalElement('custom-send')).toBe(sender)
    expect(field.value).toBe('custom draft')
    expect(choice.value).toBe('b')
    expect(notes.value).toBe('custom notes')
    expect(fields.some((element) => element.matches(':disabled'))).toBe(false)
    expect(sender.querySelector('[role="status"]')).toBeNull()
    expect(sender.getAttribute('aria-busy')).toBeNull()
    expect(document.activeElement).toBe(sender)

    host.disabled.set(true)
    fixture.detectChanges()
    expect(sender.matches(':disabled')).toBe(true)
    sender.click()
    expect(host.sent).toHaveBeenCalledTimes(1)
    host.busy.set(true)
    fixture.detectChanges()
    host.busy.set(false)
    fixture.detectChanges()
    expect(sender.matches(':disabled')).toBe(true)
    host.disabled.set(false)
    fixture.detectChanges()
    sender.click()
    expect(host.sent).toHaveBeenCalledTimes(2)
  })

  it('never flashes a spinner on a fast reply and refocuses a custom field from the dialog', () => {
    vi.useFakeTimers()
    const fixture = TestBed.createComponent(CustomBodyHost)
    const host = fixture.componentInstance
    fixture.detectChanges()
    const field = modalElement('custom-field')
    const sender = modalElement('custom-send')
    sender.focus()
    sender.click()
    fixture.detectChanges()
    vi.advanceTimersByTime(299)
    fixture.detectChanges()
    modalElement('modal').focus()
    host.busy.set(false)
    fixture.detectChanges()
    vi.advanceTimersByTime(1)
    fixture.detectChanges()
    expect(sender.querySelector('[role="status"]')).toBeNull()
    expect(field.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(field)
  })

  it('keeps custom groups and their sender under the independent connection lock', async () => {
    const feed = modalFeed()
    const fixture = TestBed.createComponent(CustomBodyHost)
    const host = fixture.componentInstance
    host.connection.set(feed.connection)
    const settle = async () => {
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
    }
    await settle()
    const sender = modalElement('custom-send') as HTMLButtonElement
    const field = modalElement('custom-field') as HTMLInputElement
    const scope = modalElement('modal-interaction-scope')
    field.value = 'stay here'
    host.busy.set(true)
    await settle()
    expect(sender.matches(':disabled')).toBe(false)
    feed.push('disconnected')
    await settle()
    expect(modalElement('modal-interaction-scope')).toBe(scope)
    expect(sender.matches(':disabled')).toBe(true)
    host.busy.set(false)
    await settle()
    expect(field.matches(':disabled')).toBe(true)
    expect(sender.matches(':disabled')).toBe(true)
    sender.click()
    expect(host.sent).not.toHaveBeenCalled()
    feed.push('connected')
    await settle()
    expect(modalElement('custom-send')).toBe(sender)
    expect(modalElement('custom-field')).toBe(field)
    expect(field.value).toBe('stay here')
    expect(field.matches(':disabled')).toBe(false)
    expect(sender.matches(':disabled')).toBe(false)
    sender.click()
    expect(host.sent).toHaveBeenCalledTimes(1)
  })
})

/** A live shell source; the real layout owns the availability state and subscriptions. */
function modalFeed() {
  const listeners = new Map<string, Set<(value: never) => void>>()
  const source = {
    state: 'connected' as ConnectionState,
    protectedMode: PROTECTED_MODE_INACTIVE,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    firstFrameHeld: false,
    on(event: string, listener: (value: never) => void): () => void {
      const set = listeners.get(event) ?? new Set()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
  }
  return {
    connection: source as unknown as HilosConnection,
    listeners,
    push(state: ConnectionState): void {
      source.state = state
      for (const listener of listeners.get('state') ?? [])
        listener(state as never)
    },
  }
}

/** Projected content stays inside the real main fieldset and inherits the shell's DI. */
@Component({
  selector: 'test-connected-modal',
  imports: [HilosLayout, HilosModal, LoadingButton],
  template: `
    <hilos-layout
      [connection]="connection()"
      [allowDisconnectedInteraction]="allow()"
    >
      <a nav href="#nav" data-id="modal-nav">Navigation</a>
      <button
        hilosLoadingButton
        type="button"
        [opensWindow]="true"
        data-id="modal-open"
        (click)="clicks()"
      >
        Open
      </button>
      @if (present()) {
        <hilos-modal
          [(open)]="open"
          [busy]="busy()"
          [confirmOnClose]="dirty()"
          title="Edit"
          copyText="technical details"
          (cancel)="canceled()"
        >
          <a
            modalHeader
            href="#header"
            data-id="header-action"
            (click)="clicks()"
            >Header action</a
          >
          <form data-id="modal-form" (submit)="clicks()">
            <input
              data-autofocus
              data-id="modal-draft"
              [disabled]="disabled()"
              [attr.aria-describedby]="description()"
              (beforeinput)="clicks()"
              (drop)="clicks()"
            />
            <input type="file" data-id="modal-file" />
            <select data-id="modal-choice">
              <option value="a">A</option>
              <option value="b">B</option>
            </select>
            <button type="button" data-id="body-action" (click)="clicks()">
              Body action
            </button>
            <a
              href="#action"
              role="button"
              data-id="body-link"
              (click)="clicks()"
              (keydown)="clicks()"
              >Link action</a
            >
            <button
              hilosLoadingButton
              type="button"
              [loading]="busy()"
              data-id="modal-save"
              (click)="clicks()"
            >
              Save
            </button>
          </form>
          <ng-template #modalActions let-requestClose="requestClose">
            <button
              type="button"
              data-id="modal-cancel"
              (click)="requestClose()"
            >
              Cancel
            </button>
          </ng-template>
        </hilos-modal>
      }
      @if (second()) {
        <hilos-modal [open]="true" title="Second" [confirmOnClose]="true" />
      }
    </hilos-layout>
  `,
})
class ConnectedModalHost {
  readonly connection = signal(modalFeed().connection)
  readonly open = signal(true)
  readonly present = signal(true)
  readonly second = signal(false)
  readonly allow = signal(false)
  readonly busy = signal(false)
  readonly dirty = signal(false)
  readonly disabled = signal(false)
  readonly description = signal('validation')
  readonly clicks = vi.fn()
  readonly canceled = vi.fn()
}

/** Mount the actual layout and projected modal and expose the controller's issued close API. */
async function connectedModal(initiallyOpen = true) {
  const feed = modalFeed()
  const fixture = TestBed.createComponent(ConnectedModalHost)
  const host = fixture.componentInstance
  host.connection.set(feed.connection)
  host.open.set(initiallyOpen)
  const settle = async () => {
    fixture.detectChanges()
    await fixture.whenStable()
    fixture.detectChanges()
    await Promise.resolve()
  }
  await settle()
  return {
    fixture,
    feed,
    host,
    settle,
    close(): void {
      const instance = fixture.debugElement.query(By.directive(HilosModal))
        .componentInstance as unknown as { modal: ModalController }
      instance.modal.requestClose()
    },
    async push(state: ConnectionState): Promise<void> {
      feed.push(state)
      await settle()
    },
  }
}

/** The required surface of a connected modal, never a stub component. */
function modalElement(id: string): HTMLElement {
  const element = byId(id)
  expect(element).not.toBeNull()
  return element!
}

/** Check the description's reading visibility, independently of aria-describedby. */
function expectConnectionDescription(
  root: HTMLElement,
  blocked: boolean,
): void {
  const description = root.querySelector<HTMLElement>(
    '[data-id="modal-connection-description"]',
  )!
  expect(description).not.toBeNull()
  expect(description.textContent).toBe(CONNECTION_INTERACTION_COPY.unavailable)
  expect(description.hidden).toBe(!blocked)
  if (blocked) expect(getComputedStyle(description).display).not.toBe('none')
  else expect(getComputedStyle(description).display).toBe('none')
}

describe('HilosModal connection interaction (HIL-1651)', () => {
  const realClipboard = navigator.clipboard
  afterEach(() => {
    vi.restoreAllMocks()
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: realClipboard,
    })
  })

  it('keeps projected draft, selection, files, scroll and lifecycle through every outage state', async () => {
    const activate = vi.spyOn(FocusTrap.prototype, 'activate')
    const written: string[] = []
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        writeText: async (text: string) => {
          written.push(text)
        },
      },
    })
    const shell = await connectedModal()
    const root = modalElement('modal')
    expectConnectionDescription(root, false)
    const field = modalElement('modal-draft') as HTMLInputElement
    const file = modalElement('modal-file') as HTMLInputElement
    const files = file.files
    const choice = modalElement('modal-choice') as HTMLSelectElement
    const scope = modalElement('modal-interaction-scope')
    const body = root.querySelector('.modal-body')!
    const layers = layerDepths('.hilos-modal-layer')
    const activations = activate.mock.calls.length
    field.value = 'draft remains'
    field.setSelectionRange(2, 7)
    choice.value = 'b'
    body.scrollTop = 83
    field.focus()
    expect(document.querySelector('main')?.contains(root)).toBe(true)
    for (const state of [
      'connecting',
      'reconnecting',
      'disconnected',
    ] as const) {
      shell.feed.push(state)
      shell.close()
      expect(shell.host.open()).toBe(true)
      expect(field.matches(':disabled')).toBe(true)
      expect(document.activeElement).toBe(root)
      expect(modalElement('modal-open').matches(':disabled')).toBe(true)
      for (const id of [
        'modal-close',
        'modal-copy',
        'modal-cancel',
        'header-action',
        'body-action',
        'body-link',
        'modal-save',
      ])
        modalElement(id).dispatchEvent(
          new MouseEvent('click', { bubbles: true, cancelable: true }),
        )
      for (const key of ['Enter', ' ']) press(modalElement('body-link'), key)
      for (const type of ['beforeinput', 'drop']) {
        const event = new Event(type, { bubbles: true, cancelable: true })
        field.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(true)
      }
      modalElement('modal-form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
      press(root, 'Escape')
      root.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      press(root, 'Tab')
      await shell.settle()
      expectConnectionDescription(root, true)
      expect(shell.host.clicks).not.toHaveBeenCalled()
      expect(shell.host.canceled).not.toHaveBeenCalled()
      expect(written).toEqual([])
      expect(modalElement('modal-draft')).toBe(field)
      expect(modalElement('modal-file')).toBe(file)
      expect(file.files).toBe(files)
      expect(modalElement('modal-interaction-scope')).toBe(scope)
      expect(choice.value).toBe('b')
      expect(field.value).toBe('draft remains')
      expect([field.selectionStart, field.selectionEnd]).toEqual([2, 7])
      expect(body.scrollTop).toBe(83)
      expect(layerDepths('.hilos-modal-layer')).toEqual(layers)
      expect(document.body.classList.contains('modal-open')).toBe(true)
      expect(activate.mock.calls).toHaveLength(activations)
      expect(
        root.querySelector('[data-id="loading-button-spinner"]'),
      ).toBeNull()
    }
    await shell.push('connected')
    expectConnectionDescription(root, false)
    expect(field.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(field)
    expect(shell.host.clicks).not.toHaveBeenCalled()
    modalElement('body-action').click()
    expect(shell.host.clicks).toHaveBeenCalledTimes(1)
    shell.close()
    await shell.settle()
    expect(byId('modal')).toBeNull()
  })

  it('holds an existing confirm, its local description and focus until a fresh gesture', async () => {
    const shell = await connectedModal()
    shell.host.dirty.set(true)
    await shell.settle()
    shell.close()
    await shell.settle()
    const confirm = modalElement('modal-confirm')
    expectConnectionDescription(modalElement('modal'), false)
    expectConnectionDescription(confirm, false)
    modalElement('modal-confirm-discard').focus()
    await shell.push('disconnected')
    expect(document.activeElement).toBe(confirm)
    const ids = [modalElement('modal'), confirm].map((dialog) => {
      const description = dialog.querySelector<HTMLElement>(
        '[data-id="modal-connection-description"]',
      )!
      expect(description.textContent).toBe(
        CONNECTION_INTERACTION_COPY.unavailable,
      )
      expectConnectionDescription(dialog, true)
      expect(description.classList.contains('visually-hidden')).toBe(true)
      expect(
        description.closest('[aria-hidden="true"], [inert], [aria-live]'),
      ).toBeNull()
      expect(dialog.getAttribute('aria-describedby')).toBe(description.id)
      for (const button of dialog.querySelectorAll('button')) {
        expect(button.matches(':disabled')).toBe(true)
        expect(button.getAttribute('aria-describedby')).toContain(
          description.id,
        )
      }
      return description.id
    })
    expect(new Set(ids).size).toBe(2)
    expect(ids).not.toContain(HILOS_CONNECTION_STATUS_TEXT_ID)
    for (const id of ['modal-confirm-cancel', 'modal-confirm-discard'])
      modalElement(id).click()
    press(confirm, 'Escape')
    shell.close()
    await shell.settle()
    expect(modalElement('modal-confirm')).toBe(confirm)
    await shell.push('connected')
    expect(modalElement('modal-confirm')).toBe(confirm)
    expectConnectionDescription(modalElement('modal'), false)
    expectConnectionDescription(confirm, false)
    expect(confirm.hasAttribute('aria-describedby')).toBe(false)
    modalElement('modal-confirm-cancel').click()
    await shell.settle()
    expect(byId('modal-confirm')).toBeNull()
    shell.close()
    await shell.settle()
    modalElement('modal-confirm-discard').click()
    await shell.settle()
    expect(shell.host.open()).toBe(false)
  })

  it('preserves live owner disabled, descriptions and busy without taking a different focus choice', async () => {
    const shell = await connectedModal()
    await shell.push('disconnected')
    shell.host.disabled.set(true)
    shell.host.description.set('new-validation look-only')
    shell.host.busy.set(true)
    await shell.settle()
    const field = modalElement('modal-draft')
    expect(field.getAttribute('aria-describedby')).toContain(
      'new-validation look-only',
    )
    modalElement('modal-nav').focus()
    await shell.push('connected')
    expect(document.activeElement).toBe(modalElement('modal-nav'))
    expect(field.matches(':disabled')).toBe(true)
    expect(field.getAttribute('aria-describedby')).toBe(
      'new-validation look-only',
    )
    expect(closeButton()?.disabled).toBe(true)
    expect(
      (modalElement('modal-interaction-scope') as HTMLFieldSetElement).disabled,
    ).toBe(false)
    shell.close()
    expect(shell.host.open()).toBe(true)
    shell.host.busy.set(false)
    await shell.settle()
    expect(field.matches(':disabled')).toBe(true)
  })

  it('honors opt-out and external close and cleans each projected root on replacement and unmount', async () => {
    const shell = await connectedModal()
    const old = modalElement('modal')
    await shell.push('disconnected')
    shell.host.allow.set(true)
    await shell.settle()
    expectConnectionDescription(modalElement('modal'), false)
    expect(modalElement('modal-draft').matches(':disabled')).toBe(false)
    modalElement('body-action').click()
    expect(shell.host.clicks).toHaveBeenCalledTimes(1)
    shell.host.allow.set(false)
    await shell.settle()
    shell.host.open.set(false)
    await shell.settle()
    expect(old.hasAttribute('data-hilos-interaction-region')).toBe(false)
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(shell.host.canceled).not.toHaveBeenCalled()
    shell.host.open.set(true)
    await shell.settle()
    expect(modalElement('modal')).not.toBe(old)
    expect(modalElement('modal-draft').matches(':disabled')).toBe(true)
    shell.host.present.set(false)
    await shell.settle()
    shell.host.present.set(true)
    await shell.settle()
    shell.fixture.destroy()
    expect(
      [...shell.feed.listeners.values()].every((set) => set.size === 0),
    ).toBe(true)
    shell.feed.push('connected')
    expect(old.hasAttribute('aria-describedby')).toBe(false)
  })

  it('keeps the projected confirm in charge when the main binding was established before opening', async () => {
    const shell = await connectedModal(false)
    shell.host.open.set(true)
    shell.host.dirty.set(true)
    await shell.settle()
    shell.close()
    await shell.settle()
    const confirm = modalElement('modal-confirm')
    const discard = modalElement('modal-confirm-discard')
    discard.focus()
    await shell.push('disconnected')
    expect(document.activeElement).toBe(confirm)
    expect(discard.matches(':disabled')).toBe(true)
    press(confirm, 'Tab')
    expect(document.activeElement).toBe(confirm)
    await shell.push('connected')
    expect(document.activeElement).toBe(discard)
    expect(modalElement('modal-confirm')).toBe(confirm)
  })

  it('keeps a standalone modal interactive beside an unavailable shell', async () => {
    const shell = await connectedModal()
    await shell.push('disconnected')
    const standalone = TestBed.createComponent(HilosModal)
    standalone.componentRef.setInput('open', true)
    standalone.componentRef.setInput('title', 'Standalone')
    standalone.detectChanges()
    const root = standalone.nativeElement.querySelector(
      '[data-id="modal"]',
    ) as HTMLElement
    const close = root.querySelector<HTMLButtonElement>(
      '[data-id="modal-close"]',
    )!
    expectConnectionDescription(root, false)
    expect(close.matches(':disabled')).toBe(false)
    expect(root.hasAttribute('aria-describedby')).toBe(false)
    close.click()
    standalone.detectChanges()
    expect(standalone.componentInstance.open()).toBe(false)
    expect(shell.host.open()).toBe(true)
  })

  it('gives concurrent instances and their confirm layers four different local descriptions', async () => {
    const shell = await connectedModal()
    shell.host.dirty.set(true)
    await shell.settle()
    shell.close()
    await shell.settle()
    shell.host.second.set(true)
    await shell.settle()
    document
      .querySelector<HTMLButtonElement>(
        '[aria-label="Second"] [data-id="modal-close"]',
      )!
      .click()
    await shell.settle()
    await shell.push('disconnected')
    const descriptions = [
      ...document.querySelectorAll<HTMLElement>(
        '[data-id="modal-connection-description"]',
      ),
    ]
    expect(descriptions).toHaveLength(4)
    expect(new Set(descriptions.map((node) => node.id)).size).toBe(4)
    for (const node of descriptions)
      expect(
        node.closest('[aria-modal]')?.getAttribute('aria-describedby'),
      ).toBe(node.id)
  })
})
