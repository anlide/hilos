import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

import { HilosEditNotice } from '../src/HilosEditNotice.js'

// The detail panel is a HilosModal, which portals to <body>: assertions about it
// query the document, not the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosEditNotice', () => {
  it('holds the room with a hidden twin while there is nothing to say', () => {
    render(<HilosEditNotice kind={null} text="" dataId="edit-notice" />)

    expect(byId('edit-notice')).toBeNull()
    const idle = byId('edit-notice-idle')
    expect(idle).not.toBeNull()
    expect(idle?.getAttribute('aria-hidden')).toBe('true')
    expect(idle?.classList.contains('invisible')).toBe(true)
    // Every element of the row in its tallest form — the icon, the line of text
    // and a button-shaped stand-in — or the room would be short of the truth.
    expect(idle?.querySelector('i.bi')).not.toBeNull()
    expect(idle?.querySelector('span.text-truncate')).not.toBeNull()
    expect(idle?.querySelector('span.btn i.bi-info-circle')).not.toBeNull()
    // A span and not a button: the twin holds room, it does not take focus.
    expect(idle?.querySelector('button')).toBeNull()
  })

  it('draws a conflict and a deletion as warnings and an update as a quiet note', () => {
    const tones = {
      conflict: ['alert-warning', 'bi-exclamation-triangle'],
      deleted: ['alert-warning', 'bi-exclamation-triangle'],
      updated: ['alert-secondary', 'bi-arrow-repeat'],
    } as const
    for (const [kind, [tone, icon]] of Object.entries(tones)) {
      render(
        <HilosEditNotice
          kind={kind as keyof typeof tones}
          text="Something"
          dataId="edit-notice"
        />,
      )

      const row = byId('edit-notice')
      expect(row).not.toBeNull()
      expect(row?.classList.contains(tone)).toBe(true)
      expect(row?.querySelector(`i.${icon}`)).not.toBeNull()
      expect(byId('edit-notice-idle')).toBeNull()
      // The row shows; the slot above it announces. A role here too would say
      // the sentence twice.
      expect(row?.getAttribute('role')).toBeNull()
      cleanup()
    }
  })

  it('keeps one truncated line and opens the whole text from the details button', () => {
    const text =
      'Changed elsewhere to "a value long enough to run past the row"'
    render(<HilosEditNotice kind="conflict" text={text} dataId="edit-notice" />)

    const line = byId('edit-notice')?.querySelector('span.flex-grow-1')
    expect(line?.textContent).toBe(text)
    expect(line?.classList.contains('text-truncate')).toBe(true)
    const details = byId('edit-notice-details')
    expect(details?.getAttribute('aria-label')).toBe('Show details')
    fireEvent.click(details as Element)

    expect(byId('edit-notice-full')?.textContent?.trim()).toBe(text)
  })

  it('closes the panel when the message goes', () => {
    const { rerender } = render(
      <HilosEditNotice
        kind="updated"
        text="Updated just now"
        dataId="edit-notice"
      />,
    )
    fireEvent.click(byId('edit-notice-details') as Element)
    expect(byId('edit-notice-full')).not.toBeNull()

    rerender(<HilosEditNotice kind={null} text="" dataId="edit-notice" />)
    expect(byId('edit-notice-full')).toBeNull()
  })

  it('makes the slot a polite status region, with or without a message', () => {
    const { rerender } = render(
      <HilosEditNotice kind={null} text="" dataId="edit-notice" />,
    )
    const slot = byId('edit-notice-slot')
    // A region that stands there before it has anything to say is the only
    // kind the reader announces from (accessibility.md, "Live regions").
    expect(slot?.getAttribute('role')).toBe('status')
    expect(slot?.getAttribute('aria-live')).toBe('polite')

    rerender(
      <HilosEditNotice
        kind="updated"
        text="Updated just now"
        dataId="edit-notice"
      />,
    )
    expect(byId('edit-notice-slot')?.getAttribute('role')).toBe('status')
    expect(byId('edit-notice-slot')?.textContent).toContain('Updated just now')
  })
})
