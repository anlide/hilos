import { Component, signal } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { createSignal, registerFocusReturnFallback } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { HilosActionReason } from '../src/base/index.js'
import { HILOS_CONNECTION_INTERACTION } from '../src/hilosConnectionInteraction.js'

const reason = 'Cannot delete: the language has locales.'

/** A consumer keeps the primitive mounted while only its source reason clears. */
@Component({
  selector: 'test-action-reason',
  imports: [HilosActionReason],
  template: `
    <div class="hilos-button-row" tabindex="-1" data-id="fallback">
      <div class="hilos-button-group d-md-inline-flex gap-1" data-id="actions">
        @if (present()) {
          <button
            hilosActionReason
            [reason]="reason()"
            [label]="label()"
            [title]="title()"
            data-id="hilos-action-reason"
          ></button>
        }
      </div>
    </div>
  `,
})
class ReasonHost {
  readonly reason = signal<string | null>(reason)
  readonly label = signal('Why this action is unavailable')
  readonly title = signal('Action unavailable')
  readonly present = signal(true)
}

/** Find an element from the real native opener or relocated modal. */
function element(id: string): HTMLElement {
  const node = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  expect(node).not.toBeNull()
  return node!
}

/** Send a key without inventing the browser's native button activation. */
function press(node: HTMLElement, key: string, shiftKey = false): void {
  node.dispatchEvent(
    new KeyboardEvent('keydown', { key, shiftKey, bubbles: true }),
  )
}

/** Mount the actual primitive, keeping its containing view in the document. */
function mount() {
  const fixture = TestBed.createComponent(ReasonHost)
  document.body.append(fixture.nativeElement as HTMLElement)
  const detect = (): void => fixture.detectChanges()
  detect()
  return {
    fixture,
    host: fixture.componentInstance,
    detect,
    open(): HTMLButtonElement {
      const opener = element('hilos-action-reason') as HTMLButtonElement
      opener.focus()
      opener.click()
      detect()
      return opener
    },
    close(): void {
      element('hilos-action-reason-close').click()
      detect()
    },
  }
}

describe('HilosActionReason', () => {
  it('keeps only a native named button in its group and no dialog inside the button', () => {
    const h = mount()
    const opener = element('hilos-action-reason') as HTMLButtonElement
    expect(element('actions').children).toHaveLength(1)
    expect(element('actions').firstElementChild).toBe(opener)
    expect(opener.tagName).toBe('BUTTON')
    expect(opener.type).toBe('button')
    expect(opener.className).toBe('btn btn-sm btn-outline-secondary')
    expect(opener.getAttribute('aria-label')).toBe(
      'Why this action is unavailable',
    )
    expect(opener.title).toBe(reason)
    expect(opener.querySelector('i')?.className).toBe('bi bi-question-circle')
    expect(opener.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    h.open()
    expect(opener.contains(element('modal'))).toBe(false)
    expect(element('actions').contains(element('modal'))).toBe(false)
    expect(element('modal').closest('hilos-modal')?.parentElement).toBe(
      document.body,
    )
    expect(element('actions').children).toHaveLength(1)
  })

  it('hides only the opener for a null reason and refuses programmatic activation', () => {
    const h = mount()
    h.host.reason.set(null)
    h.detect()
    const opener = element('hilos-action-reason') as HTMLButtonElement
    expect(opener.hidden).toBe(true)
    expect(opener.classList.contains('d-none')).toBe(true)
    expect(opener.hasAttribute('title')).toBe(false)
    opener.click()
    h.detect()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('uses custom labels, dialog initial focus, explanation and one Close footer', () => {
    const h = mount()
    h.host.label.set('Explain deletion')
    h.host.title.set('Cannot delete')
    h.detect()
    const opener = h.open()
    const dialog = element('modal')
    expect(opener.getAttribute('aria-label')).toBe('Explain deletion')
    expect(dialog.getAttribute('role')).toBe('dialog')
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(dialog.getAttribute('aria-label')).toBe('Cannot delete')
    expect(document.activeElement).toBe(dialog)
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)
    expect(dialog.querySelectorAll('.modal-body > *')).toHaveLength(1)
    expect(dialog.querySelectorAll('.modal-footer button')).toHaveLength(1)
    expect(element('hilos-action-reason-close').textContent?.trim()).toBe(
      'Close',
    )
    expect(dialog.querySelector('input, select, textarea')).toBeNull()
  })

  it.each(['Close', 'Escape', 'backdrop', 'header'] as const)(
    'closes through %s without bubbling back into the opener and returns focus',
    (gesture) => {
      const h = mount()
      const opener = h.open()
      const dialog = element('modal')
      if (gesture === 'Close') h.close()
      else {
        if (gesture === 'header') element('modal-close').click()
        else if (gesture === 'backdrop') dialog.click()
        else press(dialog, 'Escape')
        h.detect()
      }
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      expect(document.body.classList.contains('modal-open')).toBe(false)
      expect(document.activeElement).toBe(opener)
    },
  )

  it('traps Tab in the real modal and leaves Enter on the dialog without an action', () => {
    const h = mount()
    h.open()
    const dialog = element('modal')
    press(dialog, 'Enter')
    h.detect()
    expect(document.activeElement).toBe(dialog)
    const header = element('modal-close')
    const close = element('hilos-action-reason-close')
    close.focus()
    press(close, 'Tab')
    expect(document.activeElement).toBe(header)
    press(header, 'Tab', true)
    expect(document.activeElement).toBe(close)
  })

  it('keeps the opened snapshot through changed and null sources, then reads the fresh reason', () => {
    const h = mount()
    h.open()
    const dialog = element('modal')
    h.host.reason.set('Cannot delete: the language has names.')
    h.detect()
    expect(element('hilos-action-reason').title).toBe(
      'Cannot delete: the language has names.',
    )
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)
    h.host.reason.set(null)
    h.detect()
    expect(element('hilos-action-reason').hidden).toBe(true)
    expect(element('modal')).toBe(dialog)
    expect(document.activeElement).toBe(dialog)
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)
    h.close()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    h.host.reason.set('Cannot delete: this is the default language.')
    h.detect()
    h.open()
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(
      'Cannot delete: this is the default language.',
    )
  })

  it.each(['hidden', 'disabled', 'removed'] as const)(
    'uses the registered fallback when the original opener is %s before close',
    (state) => {
      const h = mount()
      const fallback = element('fallback')
      const release = registerFocusReturnFallback(fallback, () => fallback)
      try {
        const opener = h.open()
        if (state === 'hidden') h.host.reason.set(null)
        else if (state === 'disabled') opener.disabled = true
        else opener.remove()
        h.detect()
        h.close()
        expect(document.activeElement).toBe(fallback)
        expect(document.activeElement?.isConnected).toBe(true)
      } finally {
        release()
      }
    },
  )

  it('destroys its relocated DOM and releases scroll and focus when the primitive leaves', () => {
    const h = mount()
    h.open()
    const modalHost = element('modal').closest('hilos-modal')!
    expect(document.body.classList.contains('modal-open')).toBe(true)
    h.host.present.set(false)
    h.detect()
    expect(modalHost.isConnected).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('.modal-backdrop')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(document.activeElement?.isConnected).toBe(true)
    h.host.present.set(true)
    h.detect()
    h.open()
    h.fixture.destroy()
    expect(document.querySelector('hilos-modal')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('preserves declaration DI and the standard connection lock after relocation', async () => {
    const blocked = createSignal(false)
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_CONNECTION_INTERACTION,
          useValue: createSignal({ unavailable: blocked, blocked }),
        },
      ],
    })
    const h = mount()
    h.open()
    const dialog = element('modal')
    const close = element('hilos-action-reason-close')
    blocked.set(true)
    h.detect()
    await h.fixture.whenStable()
    h.detect()
    expect(close.matches(':disabled')).toBe(true)
    expect(dialog.getAttribute('aria-describedby')).toBe(
      dialog.querySelector('[data-id="modal-connection-description"]')?.id,
    )
    close.click()
    press(dialog, 'Escape')
    h.detect()
    expect(element('modal')).toBe(dialog)
    blocked.set(false)
    h.detect()
    await h.fixture.whenStable()
    h.detect()
    expect(close.matches(':disabled')).toBe(false)
    h.close()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })
})
