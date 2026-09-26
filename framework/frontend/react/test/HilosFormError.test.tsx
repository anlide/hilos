import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

import { HilosFormError } from '../src/HilosFormError.js'

// The detail panel is a HilosModal, which portals to <body>: assertions about it
// query the document, not the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
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
    render(<HilosFormError message={null} dataId="auth-error" />)

    const slot = byId('auth-error-slot')
    expect(slot).not.toBeNull()
    // No role anywhere on what is seen: the surface's own live region does the
    // announcing, and a second role would say the sentence twice.
    expect(slot?.getAttribute('role')).toBeNull()
    expect(byId('auth-error')).toBeNull()
  })

  it('holds the room with a hidden twin of the whole row', () => {
    render(<HilosFormError message={null} dataId="auth-error" />)

    const idle = byId('auth-error-idle')
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
    render(<HilosFormError message="" dataId="auth-error" />)

    expect(byId('auth-error-idle')).not.toBeNull()
    expect(byId('auth-error')).toBeNull()
  })

  it('draws the refusal in one truncated line and carries no role', () => {
    render(<HilosFormError message="Incorrect password" dataId="auth-error" />)

    const row = byId('auth-error')
    expect(row).not.toBeNull()
    expect(row?.getAttribute('role')).toBeNull()
    expect(byId('auth-error-idle')).toBeNull()
    const text = row?.querySelector('span.flex-grow-1')
    expect(text?.textContent).toBe('Incorrect password')
    expect(text?.classList.contains('text-truncate')).toBe(true)
  })

  it('opens the whole sentence from the details button', () => {
    render(<HilosFormError message="A very long refusal" dataId="auth-error" />)

    const details = byId('auth-error-details')
    expect(details).not.toBeNull()
    expect(details?.getAttribute('aria-label')).toBe('Show error details')
    fireEvent.click(details as HTMLElement)

    expect(byId('auth-error-full')?.textContent).toBe('A very long refusal')
  })

  it('closes the panel when the refusal is cleared', () => {
    const view = render(
      <HilosFormError message="A very long refusal" dataId="auth-error" />,
    )
    fireEvent.click(byId('auth-error-details') as HTMLElement)
    expect(byId('auth-error-full')).not.toBeNull()

    // The form re-arms on the next attempt, and a panel left open would be
    // showing the previous refusal's text.
    view.rerender(<HilosFormError message={null} dataId="auth-error" />)
    expect(byId('auth-error-full')).toBeNull()
  })

  it('makes the slot a live region only when asked, and never the row', () => {
    const quiet = render(<HilosFormError message="Refused" dataId="quiet" />)
    expect(byId('quiet-slot')?.getAttribute('role')).toBeNull()
    expect(byId('quiet-slot')?.getAttribute('aria-live')).toBeNull()
    quiet.unmount()

    render(<HilosFormError message="Refused" dataId="loud" announce />)
    expect(byId('loud-slot')?.getAttribute('role')).toBe('alert')
    expect(byId('loud-slot')?.getAttribute('aria-live')).toBe('assertive')
    // The region is the slot; a role on the row too would say it twice.
    expect(byId('loud')?.getAttribute('role')).toBeNull()
  })

  it('draws the class name inside the details button, and only there', () => {
    const typed = render(
      <HilosFormError message="Refused" dataId="e" errorType="PDOException" />,
    )
    const type = document.querySelector(
      '[data-id="e-details"] [data-id="e-type"]',
    )
    expect(type?.textContent).toBe('PDOException')
    typed.unmount()

    const plain = render(<HilosFormError message="Refused" dataId="e" />)
    expect(byId('e-type')).toBeNull()
    plain.unmount()

    // The twin holds the room of the bare icon, not of a name.
    render(
      <HilosFormError message={null} dataId="e" errorType="PDOException" />,
    )
    expect(byId('e-type')).toBeNull()
  })

  it('gives the details button and its twin the same classes', () => {
    const shown = render(<HilosFormError message="Refused" dataId="e" />)
    const button = byId('e-details')?.className
    shown.unmount()

    render(<HilosFormError message={null} dataId="e" />)
    const twin = document.querySelector('[data-id="e-idle"] span.btn')
    expect(twin?.className).toBe(button)
    expect(twin?.classList.contains('btn-link')).toBe(true)
    expect(twin?.classList.contains('text-decoration-none')).toBe(true)
  })

  it('shows the original text under its class name in the panel', () => {
    render(
      <HilosFormError
        message="Could not save"
        dataId="e"
        errorType="PDOException"
        errorDetail="SQLSTATE[23000]"
      />,
    )
    fireEvent.click(byId('e-details') as HTMLElement)

    expect(document.querySelector('.modal-title')?.textContent).toBe(
      'Error details',
    )
    expect(byId('e-close')).not.toBeNull()
    expect(byId('e-detail')?.textContent).toBe('PDOException\nSQLSTATE[23000]')
  })

  it('heads the details panel with the title it is given', () => {
    render(
      <HilosFormError
        message="Refused"
        dataId="e"
        detailsTitle="Couldn't save"
      />,
    )
    fireEvent.click(byId('e-details') as HTMLElement)

    expect(document.querySelector('.modal-title')?.textContent).toBe(
      "Couldn't save",
    )
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toBe("Couldn't save")
  })

  it('draws no original-text block without one', () => {
    render(<HilosFormError message="Refused" dataId="e" />)
    fireEvent.click(byId('e-details') as HTMLElement)

    expect(byId('e-full')).not.toBeNull()
    expect(byId('e-close')).not.toBeNull()
    expect(byId('e-detail')).toBeNull()
  })

  it('offers Copy only when given something to copy', () => {
    // Copy stands only where there is a clipboard to write to.
    const realClipboard = navigator.clipboard
    setClipboard({ writeText: async () => {} } as unknown as Clipboard)
    const bare = render(<HilosFormError message="Refused" dataId="e" />)
    fireEvent.click(byId('e-details') as HTMLElement)
    expect(byId('modal-copy')).toBeNull()
    bare.unmount()

    render(<HilosFormError message="Refused" dataId="e" copyText="Refused" />)
    fireEvent.click(byId('e-details') as HTMLElement)
    expect(byId('modal-copy')).not.toBeNull()
    setClipboard(realClipboard)
  })
})
