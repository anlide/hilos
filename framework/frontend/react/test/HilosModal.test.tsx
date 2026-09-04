import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'

import { HilosModal } from '../src/HilosModal.js'

// HilosModal portals to <body>, so assertions query the document, not the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosModal', () => {
  it('renders nothing when closed', () => {
    render(<HilosModal open={false} />)
    expect(byId('modal')).toBeNull()
  })

  it('renders the dialog and backdrop and locks scroll when open', () => {
    render(<HilosModal open title="Edit" />)
    expect(byId('modal')).not.toBeNull()
    expect(document.querySelector('.modal-backdrop')).not.toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('moves focus into the dialog on open', () => {
    render(<HilosModal open />)
    expect(byId('modal')?.contains(document.activeElement)).toBe(true)
  })

  it('calls onOk from the default OK button', () => {
    let oks = 0
    render(
      <HilosModal
        open
        onOk={() => {
          oks += 1
        }}
      />,
    )
    fireEvent.click(
      document.querySelector('.modal-footer .btn-primary') as Element,
    )
    expect(oks).toBe(1)
  })

  it('renders no footer at all when showFooter is false', () => {
    render(<HilosModal open showFooter={false} />)
    expect(document.querySelector('.modal-footer')).toBeNull()
  })

  it('calls onClose from the close button when not guarding', () => {
    let closes = 0
    render(
      <HilosModal
        open
        onClose={() => {
          closes += 1
        }}
      />,
    )
    fireEvent.click(byId('modal-close') as Element)
    expect(closes).toBe(1)
  })

  it('names the dialog from ariaLabel when it carries no visible title', () => {
    render(<HilosModal open ariaLabel="Sign in" />)
    expect(byId('modal')?.getAttribute('aria-label')).toBe('Sign in')
  })

  it('names the dialog from ariaLabelledby when it carries no visible title', () => {
    render(
      <HilosModal open ariaLabel="Sign in" ariaLabelledby="body-heading" />,
    )
    expect(byId('modal')?.getAttribute('aria-labelledby')).toBe('body-heading')
    // The fallback name stays put: a surface carrying no such heading leaves
    // aria-labelledby resolving to nothing, and the name falls back to it.
    expect(byId('modal')?.getAttribute('aria-label')).toBe('Sign in')
  })

  it('drops ariaLabelledby when the dialog has a visible title', () => {
    render(<HilosModal open title="Edit" ariaLabelledby="body-heading" />)
    expect(byId('modal')?.getAttribute('aria-labelledby')).toBeNull()
    expect(byId('modal')?.getAttribute('aria-label')).toBe('Edit')
  })

  it('renders no modal-title heading when there is no title', () => {
    // An empty heading is a heading in the accessibility tree that names
    // nothing (docs/agents/frontend/accessibility.md).
    render(<HilosModal open ariaLabel="Sign in" />)
    expect(document.querySelector('.modal-title')).toBeNull()
  })

  it('raises a confirm step before closing a dirty modal, then discards', () => {
    let closes = 0
    render(
      <HilosModal
        open
        confirmOnClose
        onClose={() => {
          closes += 1
        }}
      />,
    )
    fireEvent.click(byId('modal-close') as Element)
    expect(byId('modal-confirm')).not.toBeNull()
    expect(closes).toBe(0)

    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(closes).toBe(1)
  })
})
