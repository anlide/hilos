import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'

import { HilosModal } from '../src/HilosModal.js'

// HilosModal portals to <body>, so assertions query the document, not the render.
const realClipboard = navigator.clipboard
const written: string[] = []

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
  setClipboard(realClipboard)
  written.length = 0
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

/** A clipboard that records what was written to it. */
function recordingClipboard(): void {
  setClipboard({
    writeText: async (text: string) => {
      written.push(text)
    },
  } as unknown as Clipboard)
}

/** Wait out the microtasks a clipboard write resolves through. */
async function settled(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
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

  it('keeps scroll locked when an open modal contains a closed modal', () => {
    render(
      <HilosModal open>
        <HilosModal open={false} />
      </HilosModal>,
    )

    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('keeps scroll locked when one of two open modals closes', () => {
    const view = render(
      <>
        <HilosModal open />
        <HilosModal open />
      </>,
    )

    view.rerender(
      <>
        <HilosModal open={false} />
        <HilosModal open />
      </>,
    )

    expect(document.body.classList.contains('modal-open')).toBe(true)
  })

  it('moves focus into the dialog on open', () => {
    render(<HilosModal open />)
    expect(byId('modal')?.contains(document.activeElement)).toBe(true)
  })

  it('focuses a marked child when the dialog opens', () => {
    render(
      <HilosModal open>
        <input data-autofocus data-id="field" />
      </HilosModal>,
    )
    expect(document.activeElement).toBe(byId('field'))
  })

  it('focuses the dialog when initialFocus is dialog', () => {
    render(<HilosModal open initialFocus="dialog" />)
    expect(document.activeElement).toBe(byId('modal'))
  })

  it('focuses the confirm dialog, not Discard, on the discard-confirm step', () => {
    render(<HilosModal open confirmOnClose />)
    fireEvent.click(byId('modal-close') as Element)
    expect(document.activeElement).toBe(byId('modal-confirm'))
    expect(document.activeElement).not.toBe(byId('modal-confirm-discard'))
  })

  it('renders no footer at all when the dialog declares no actions', () => {
    render(<HilosModal open />)
    expect(document.querySelector('.modal-footer')).toBeNull()
  })

  it('renders the footer with exactly the declared actions', () => {
    render(
      <HilosModal
        open
        actions={() => (
          <button type="button" data-id="only-one">
            Close
          </button>
        )}
      />,
    )
    const footer = document.querySelector('.modal-footer')
    expect(footer).not.toBeNull()
    expect(footer?.querySelectorAll('button')).toHaveLength(1)
    expect(footer?.querySelector('[data-id="only-one"]')).not.toBeNull()
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

  it('gives both dialogs a scrollable body and a narrow-screen sheet', () => {
    render(<HilosModal open confirmOnClose />)
    fireEvent.click(byId('modal-close') as Element)

    const dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs).toHaveLength(2)
    dialogs.forEach((dialog) => {
      expect(dialog.classList.contains('modal-dialog-scrollable')).toBe(true)
      expect(dialog.classList.contains('hilos-modal-sheet')).toBe(true)
    })
  })

  it('draws a footer with just the copy button when there are no actions', () => {
    recordingClipboard()
    render(<HilosModal open copyText="hilos restore --archive x" />)
    const footer = document.querySelector('.modal-footer')
    expect(footer).not.toBeNull()
    expect(footer?.querySelectorAll('button')).toHaveLength(1)
    expect(footer?.querySelector('[data-id="modal-copy"]')).not.toBeNull()
  })

  it('draws no copy button when there is nothing to copy', () => {
    recordingClipboard()
    render(<HilosModal open />)
    expect(byId('modal-copy')).toBeNull()
  })

  it('draws no copy button when the document has no clipboard', () => {
    // Over plain http there is none, and a button that silently does nothing
    // is worse than no button at all.
    setClipboard(undefined)
    render(<HilosModal open copyText="hilos restore --archive x" />)
    expect(byId('modal-copy')).toBeNull()
  })

  it('writes the text to the clipboard and says it did', async () => {
    recordingClipboard()
    render(<HilosModal open copyText="hilos restore --archive x" />)
    expect(byId('modal-copy')?.textContent?.trim()).toBe('Copy')

    fireEvent.click(byId('modal-copy') as Element)
    await settled()
    expect(written).toEqual(['hilos restore --archive x'])
    expect(byId('modal-copy')?.textContent?.trim()).toBe('Copied')
  })

  it('says Copy again the next time the dialog is opened', async () => {
    recordingClipboard()
    const view = render(
      <HilosModal open copyText="hilos restore --archive x" />,
    )
    fireEvent.click(byId('modal-copy') as Element)
    await settled()

    view.rerender(<HilosModal open={false} copyText="hilos restore x" />)
    view.rerender(<HilosModal open copyText="hilos restore --archive x" />)
    expect(byId('modal-copy')?.textContent?.trim()).toBe('Copy')
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
