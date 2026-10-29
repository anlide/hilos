import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { registerFocusReturnFallback } from '@hilos/core/base'

import { HilosActionReason } from '../src/HilosActionReason.js'

const reason = 'Cannot delete: the language has locales.'

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

/** Find a required element from the real portaled dialog or opener. */
function element(id: string): HTMLElement {
  const node = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  expect(node).not.toBeNull()
  return node!
}

/** Focus and activate the native opener so the modal captures its true return target. */
function openReason(): HTMLButtonElement {
  const opener = element('hilos-action-reason') as HTMLButtonElement
  opener.focus()
  fireEvent.click(opener)
  return opener
}

describe('HilosActionReason', () => {
  it('renders a named native opener without a wrapper and keeps the reason as its title', () => {
    const view = render(<HilosActionReason reason={reason} />)
    const opener = element('hilos-action-reason') as HTMLButtonElement

    expect(view.container.children).toHaveLength(1)
    expect(view.container.firstElementChild).toBe(opener)
    expect(opener.type).toBe('button')
    expect(opener.className).toBe('btn btn-sm btn-outline-secondary')
    expect(opener.getAttribute('aria-label')).toBe(
      'Why this action is unavailable',
    )
    expect(opener.title).toBe(reason)
    expect(opener.querySelector('i')?.className).toBe('bi bi-question-circle')
    expect(opener.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('renders no opener or modal for a null reason', () => {
    const view = render(<HilosActionReason reason={null} />)

    expect(view.container.querySelector('button')).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
  })

  it('uses custom labels and focuses the named dialog when opened', () => {
    const view = render(<HilosActionReason reason={reason} />)
    view.rerender(
      <HilosActionReason
        reason={reason}
        label="Explain deletion"
        title="Cannot delete"
      />,
    )
    const opener = element('hilos-action-reason') as HTMLButtonElement
    expect(opener.getAttribute('aria-label')).toBe('Explain deletion')

    opener.focus()
    fireEvent.click(opener)

    const dialog = element('modal')
    expect(dialog.getAttribute('role')).toBe('dialog')
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(dialog.getAttribute('aria-label')).toBe('Cannot delete')
    expect(document.activeElement).toBe(dialog)
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)
    expect(dialog.querySelectorAll('.modal-body > *')).toHaveLength(1)
    expect(dialog.querySelectorAll('.modal-body > fieldset > *')).toHaveLength(
      1,
    )
    expect(
      dialog.querySelector('.modal-body > fieldset > p')?.textContent?.trim(),
    ).toBe(reason)
    expect(dialog.querySelectorAll('.modal-footer button')).toHaveLength(1)
    expect(element('hilos-action-reason-close').textContent?.trim()).toBe(
      'Close',
    )
    expect(dialog.querySelector('input, select, textarea')).toBeNull()
  })

  it.each(['Close', 'Escape', 'backdrop', 'header'] as const)(
    'closes through %s and returns focus to the opener',
    (gesture) => {
      const view = render(<HilosActionReason reason={reason} />)
      const opener = openReason()
      const dialog = element('modal')

      if (gesture === 'Close')
        fireEvent.click(element('hilos-action-reason-close'))
      else if (gesture === 'header') fireEvent.click(element('modal-close'))
      else if (gesture === 'backdrop') fireEvent.click(dialog)
      else fireEvent.keyDown(dialog, { key: 'Escape' })

      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      expect(document.body.classList.contains('modal-open')).toBe(false)
      expect(document.activeElement).toBe(opener)
      view.unmount()
    },
  )

  it('keeps Tab inside the real dialog and Enter on the dialog does not activate an action', () => {
    render(<HilosActionReason reason={reason} />)
    openReason()
    const dialog = element('modal')

    fireEvent.keyDown(dialog, { key: 'Enter' })
    expect(document.activeElement).toBe(dialog)

    const headerClose = element('modal-close')
    const close = element('hilos-action-reason-close')
    close.focus()
    fireEvent.keyDown(close, { key: 'Tab' })
    expect(document.activeElement).toBe(headerClose)
    fireEvent.keyDown(headerClose, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(close)
  })

  it('keeps the opened snapshot when the live reason changes or disappears', () => {
    const view = render(<HilosActionReason reason={reason} />)
    openReason()
    const dialog = element('modal')

    view.rerender(
      <HilosActionReason reason="Cannot delete: the language has names." />,
    )
    expect(element('hilos-action-reason').getAttribute('title')).toBe(
      'Cannot delete: the language has names.',
    )
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)

    view.rerender(<HilosActionReason reason={null} />)
    expect(
      view.container.querySelector('[data-id="hilos-action-reason"]'),
    ).toBe(null)
    expect(element('modal')).toBe(dialog)
    expect(document.activeElement).toBe(dialog)
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(reason)

    fireEvent.click(element('hilos-action-reason-close'))
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    view.rerender(
      <HilosActionReason reason="Cannot delete: this is the default language." />,
    )
    fireEvent.click(element('hilos-action-reason'))
    expect(element('hilos-action-reason-body').textContent?.trim()).toBe(
      'Cannot delete: this is the default language.',
    )
  })

  it.each(['removed', 'disabled'] as const)(
    'uses the registered fallback when the opener is %s before close',
    (state) => {
      const host = document.createElement('div')
      host.tabIndex = -1
      document.body.append(host)
      const unregister = registerFocusReturnFallback(host, () => host)
      const view = render(<HilosActionReason reason={reason} />, {
        container: host,
      })

      try {
        const opener = openReason()
        if (state === 'removed') {
          view.rerender(<HilosActionReason reason={null} />)
        } else {
          opener.disabled = true
        }
        fireEvent.click(element('hilos-action-reason-close'))
        expect(document.activeElement).toBe(host)
        expect(document.activeElement?.isConnected).toBe(true)
      } finally {
        unregister()
        view.unmount()
        host.remove()
      }
    },
  )

  it('releases the real modal and scroll lock when unmounted while open', () => {
    const view = render(<HilosActionReason reason={reason} />)
    openReason()
    expect(document.body.classList.contains('modal-open')).toBe(true)

    view.unmount()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('.modal-backdrop')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(document.activeElement?.isConnected).toBe(true)
  })
})
