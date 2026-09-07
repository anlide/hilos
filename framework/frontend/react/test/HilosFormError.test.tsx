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
    expect(details?.getAttribute('aria-label')).toBe('Show the full message')
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
})
