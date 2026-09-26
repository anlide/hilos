// The Angular port of react/test/HilosFormError.test.tsx and
// vue/src/HilosFormError.test.ts, under the same case names: the base refusal
// row, and what an action's refusal switches on in it.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { HilosFormError } from '../src/HilosFormError.js'

/** A host that drives every input of the row through signals. */
@Component({
  selector: 'test-form-error-host',
  imports: [HilosFormError],
  template: `
    <hilos-form-error
      [message]="message()"
      dataId="e"
      [errorType]="errorType()"
      [errorDetail]="errorDetail()"
      [copyText]="copyText()"
      [announce]="announce()"
    />
  `,
})
class FormErrorHost {
  readonly message = signal<string | null>(null)
  readonly errorType = signal<string | null>(null)
  readonly errorDetail = signal<string | null>(null)
  readonly copyText = signal('')
  readonly announce = signal(false)
}

/** A host that heads the details panel the way a tracked action's plate does. */
@Component({
  selector: 'test-titled-form-error-host',
  imports: [HilosFormError],
  template: `
    <hilos-form-error
      message="Refused"
      dataId="e"
      detailsTitle="Couldn't save"
    />
  `,
})
class TitledFormErrorHost {}

/** What a test sets on the host before the first render. */
interface HostInputs {
  message?: string | null
  errorType?: string | null
  errorDetail?: string | null
  copyText?: string
  announce?: boolean
}

/**
 * Mount the host with the given inputs and render it once.
 *
 * @param inputs The inputs to set before the first render.
 * @returns The mounted fixture.
 */
function mountRow(inputs: HostInputs = {}): ComponentFixture<FormErrorHost> {
  const fixture = TestBed.createComponent(FormErrorHost)
  const host = fixture.componentInstance
  host.message.set(inputs.message ?? null)
  host.errorType.set(inputs.errorType ?? null)
  host.errorDetail.set(inputs.errorDetail ?? null)
  host.copyText.set(inputs.copyText ?? '')
  host.announce.set(inputs.announce ?? false)
  fixture.detectChanges()

  return fixture
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/**
 * Open the details panel from the row's button.
 *
 * @param fixture The mounted host.
 */
function openDetails(fixture: ComponentFixture<FormErrorHost>): void {
  byId('e-details')?.click()
  fixture.detectChanges()
}

/** Put a clipboard in the document, or take it away — plain http has none. */
function setClipboard(clipboard: Clipboard | undefined): void {
  Object.defineProperty(navigator, 'clipboard', {
    value: clipboard,
    configurable: true,
  })
}

describe('HilosFormError', () => {
  it('keeps the slot and draws no row while there is no refusal', () => {
    mountRow()

    const slot = byId('e-slot')
    expect(slot).not.toBeNull()
    // No role anywhere on what is seen: the surface's own live region does the
    // announcing, and a second role would say the sentence twice.
    expect(slot?.getAttribute('role')).toBeNull()
    expect(byId('e')).toBeNull()
  })

  it('holds the room with a hidden twin of the whole row', () => {
    mountRow()

    const idle = byId('e-idle')
    expect(idle).not.toBeNull()
    expect(idle?.getAttribute('aria-hidden')).toBe('true')
    expect(idle?.classList.contains('invisible')).toBe(true)
    // Every element of the row in its tallest form — the icon, the line of text
    // and a button-shaped stand-in — or the room would be short of the truth.
    expect(idle?.querySelector('i.bi-exclamation-circle')).not.toBeNull()
    expect(idle?.querySelector('span.text-truncate')).not.toBeNull()
    expect(idle?.querySelector('span.btn i.bi-info-circle')).not.toBeNull()
    // A span and not a button: the twin holds room, it does not take focus.
    expect(idle?.querySelector('button')).toBeNull()
  })

  it('treats an empty message exactly as no message', () => {
    mountRow({ message: '' })

    expect(byId('e-idle')).not.toBeNull()
    expect(byId('e')).toBeNull()
  })

  it('draws the refusal in one truncated line and carries no role', () => {
    mountRow({ message: 'Incorrect password' })

    const row = byId('e')
    expect(row).not.toBeNull()
    expect(row?.getAttribute('role')).toBeNull()
    expect(byId('e-idle')).toBeNull()
    const text = row?.querySelector('span.flex-grow-1')
    expect(text?.textContent).toBe('Incorrect password')
    expect(text?.classList.contains('text-truncate')).toBe(true)
  })

  it('opens the whole sentence from the details button', () => {
    const fixture = mountRow({ message: 'A very long refusal' })

    const details = byId('e-details')
    expect(details).not.toBeNull()
    expect(details?.getAttribute('aria-label')).toBe('Show error details')
    openDetails(fixture)

    expect(byId('e-full')?.textContent?.trim()).toBe('A very long refusal')
  })

  it('closes the panel when the refusal is cleared', () => {
    const fixture = mountRow({ message: 'A very long refusal' })
    openDetails(fixture)
    expect(byId('e-full')).not.toBeNull()

    // The form re-arms on the next attempt, and a panel left open would be
    // showing the previous refusal's text.
    fixture.componentInstance.message.set(null)
    fixture.detectChanges()
    expect(byId('e-full')).toBeNull()
  })

  it('makes the slot a live region only when asked, and never the row', () => {
    const quiet = mountRow({ message: 'Refused' })
    expect(byId('e-slot')?.getAttribute('role')).toBeNull()
    expect(byId('e-slot')?.getAttribute('aria-live')).toBeNull()
    quiet.destroy()

    mountRow({ message: 'Refused', announce: true })
    expect(byId('e-slot')?.getAttribute('role')).toBe('alert')
    expect(byId('e-slot')?.getAttribute('aria-live')).toBe('assertive')
    // The region is the slot; a role on the row too would say it twice.
    expect(byId('e')?.getAttribute('role')).toBeNull()
  })

  it('draws the class name inside the details button, and only there', () => {
    const typed = mountRow({ message: 'Refused', errorType: 'PDOException' })
    const type = document.querySelector(
      '[data-id="e-details"] [data-id="e-type"]',
    )
    expect(type?.textContent).toBe('PDOException')
    typed.destroy()

    const plain = mountRow({ message: 'Refused' })
    expect(byId('e-type')).toBeNull()
    plain.destroy()

    // The twin holds the room of the bare icon, not of a name.
    mountRow({ errorType: 'PDOException' })
    expect(byId('e-type')).toBeNull()
  })

  it('gives the details button and its twin the same classes', () => {
    const shown = mountRow({ message: 'Refused' })
    const button = byId('e-details')?.className
    shown.destroy()

    mountRow()
    const twin = document.querySelector('[data-id="e-idle"] span.btn')
    expect(twin?.className).toBe(button)
    expect(twin?.classList.contains('btn-link')).toBe(true)
    expect(twin?.classList.contains('text-decoration-none')).toBe(true)
  })

  it('shows the original text under its class name in the panel', () => {
    const fixture = mountRow({
      message: 'Could not save',
      errorType: 'PDOException',
      errorDetail: 'SQLSTATE[23000]',
    })
    openDetails(fixture)

    expect(document.querySelector('.modal-title')?.textContent).toBe(
      'Error details',
    )
    expect(byId('e-close')).not.toBeNull()
    expect(byId('e-detail')?.textContent).toBe('PDOException\nSQLSTATE[23000]')
  })

  it('heads the details panel with the title it is given', () => {
    const fixture = TestBed.createComponent(TitledFormErrorHost)
    fixture.detectChanges()
    byId('e-details')?.click()
    fixture.detectChanges()

    expect(document.querySelector('.modal-title')?.textContent).toBe(
      "Couldn't save",
    )
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toBe("Couldn't save")
  })

  it('draws no original-text block without one', () => {
    const fixture = mountRow({ message: 'Refused' })
    openDetails(fixture)

    expect(byId('e-full')).not.toBeNull()
    expect(byId('e-close')).not.toBeNull()
    expect(byId('e-detail')).toBeNull()
  })

  it('offers Copy only when given something to copy', () => {
    // Copy stands only where there is a clipboard to write to.
    const realClipboard = navigator.clipboard
    setClipboard({ writeText: async () => {} } as unknown as Clipboard)
    const bare = mountRow({ message: 'Refused' })
    openDetails(bare)
    expect(byId('modal-copy')).toBeNull()
    bare.destroy()

    const copied = mountRow({ message: 'Refused', copyText: 'Refused' })
    openDetails(copied)
    expect(byId('modal-copy')).not.toBeNull()
    setClipboard(realClipboard)
  })
})
