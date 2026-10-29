import { afterEach, describe, expect, it, vi } from 'vitest'
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  CONNECTION_INTERACTION_COPY,
  FocusTrap,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  type ConnectionState,
  type HilosConnection,
} from '@hilos/core/base'

import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { LoadingButton } from '../src/LoadingButton.js'

// HilosModal portals to <body>, so assertions query the document, not the render.
const realClipboard = navigator.clipboard
const written: string[] = []

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
  setClipboard(realClipboard)
  written.length = 0
  vi.restoreAllMocks()
  vi.useRealTimers()
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
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

  it('stands a lone modal on layer 0, backdrop and dialog alike', () => {
    render(<HilosModal open title="Edit" />)

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(layerDepths('[data-id="modal"]')).toEqual(['0'])
  })

  it('stands a modal opened inside an open modal on layer 1', () => {
    const signIn = (detailsOpen: boolean) => (
      <HilosModal open title="Sign in">
        <HilosModal open={detailsOpen} title="Error details" />
      </HilosModal>
    )
    const view = render(signIn(false))

    view.rerender(signIn(true))

    expect(layerDepths('.modal-backdrop').sort()).toEqual(['0', '1'])
    expect(layerDepths('[aria-label="Sign in"]')).toEqual(['0'])
    expect(layerDepths('[aria-label="Error details"]')).toEqual(['1'])
  })

  it('stands the confirm step one layer above its own modal', () => {
    const signIn = (editOpen: boolean) => (
      <HilosModal open title="Sign in">
        <HilosModal open={editOpen} title="Edit" confirmOnClose />
      </HilosModal>
    )
    const view = render(signIn(false))
    view.rerender(signIn(true))

    fireEvent.click(
      document.querySelector(
        '[aria-label="Edit"] [data-id="modal-close"]',
      ) as Element,
    )

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['2'])
    expect(layerDepths('.modal-backdrop').sort()).toEqual(['0', '1', '2'])
  })

  it('stands the confirm step on layer 1 over a lone modal', () => {
    render(<HilosModal open title="Edit" confirmOnClose />)

    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )

    expect(layerDepths('[data-id="modal-confirm"]')).toEqual(['1'])
    expect(layerDepths('.modal-backdrop').sort()).toEqual(['0', '1'])
  })

  it('releases the confirm step layer on keep editing', () => {
    const pair = (detailsOpen: boolean) => (
      <>
        <HilosModal open title="Edit" confirmOnClose />
        <HilosModal open={detailsOpen} title="Details" />
      </>
    )
    const view = render(pair(false))

    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )
    fireEvent.click(
      document.querySelector('[data-id="modal-confirm-cancel"]') as Element,
    )

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    view.rerender(pair(true))

    expect(layerDepths('[aria-label="Details"]')).toEqual(['1'])
  })

  it('releases the confirm step layer on discard', () => {
    const pair = (detailsOpen: boolean) => (
      <>
        <HilosModal open title="Edit" confirmOnClose />
        <HilosModal open={detailsOpen} title="Details" />
      </>
    )
    const view = render(pair(false))

    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )
    fireEvent.click(
      document.querySelector('[data-id="modal-confirm-discard"]') as Element,
    )

    expect(layerDepths('.modal-backdrop')).toEqual(['0'])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    view.rerender(pair(true))

    expect(layerDepths('[aria-label="Details"]')).toEqual(['1'])
  })

  it('releases both modal and confirm step layers on discard when modal closes', () => {
    const pair = (editOpen: boolean, detailsOpen: boolean) => (
      <>
        <HilosModal open={editOpen} title="Edit" confirmOnClose />
        <HilosModal open={detailsOpen} title="Details" />
      </>
    )
    const view = render(pair(true, false))

    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )
    fireEvent.click(
      document.querySelector('[data-id="modal-confirm-discard"]') as Element,
    )

    view.rerender(pair(false, false))

    expect(layerDepths('.modal-backdrop')).toEqual([])
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    view.rerender(pair(false, true))

    expect(layerDepths('[aria-label="Details"]')).toEqual(['0'])
  })

  it('opens the next modal over a lone one on layer 1 again after the upper closed', () => {
    const pair = (detailsOpen: boolean) => (
      <>
        <HilosModal open title="Sign in" />
        <HilosModal open={detailsOpen} title="Error details" />
      </>
    )
    const view = render(pair(false))
    view.rerender(pair(true))
    view.rerender(pair(false))

    view.rerender(pair(true))

    expect(layerDepths('[aria-label="Error details"]')).toEqual(['1'])
  })

  it('closes only the upper layer on Escape pressed in it', () => {
    const closed: string[] = []
    const signIn = (detailsOpen: boolean) => (
      <HilosModal open title="Sign in" onClose={() => closed.push('Sign in')}>
        <HilosModal
          open={detailsOpen}
          title="Error details"
          onClose={() => closed.push('Error details')}
        />
      </HilosModal>
    )
    const view = render(signIn(false))
    view.rerender(signIn(true))

    fireEvent.keyDown(
      document.querySelector('[aria-label="Error details"]') as Element,
      { key: 'Escape' },
    )

    expect(closed).toEqual(['Error details'])
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

  it('shapes both footers with hilos-button-row and no column reverse', () => {
    render(
      <HilosModal
        open
        confirmOnClose
        actions={() => <button type="button">Save</button>}
      />,
    )
    fireEvent.click(byId('modal-close') as Element)

    const footers = document.querySelectorAll('.modal-footer')
    expect(footers).toHaveLength(2)
    footers.forEach((footer) => {
      expect(footer.classList.contains('hilos-button-row')).toBe(true)
      expect(footer.classList.contains('flex-column-reverse')).toBe(false)
    })
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

  it('names the dialog from aria-label when it carries no visible title', () => {
    render(<HilosModal open aria-label="Sign in" />)
    expect(byId('modal')?.getAttribute('aria-label')).toBe('Sign in')
  })

  it('names the dialog from aria-labelledby when it carries no visible title', () => {
    render(
      <HilosModal open aria-label="Sign in" aria-labelledby="body-heading" />,
    )
    expect(byId('modal')?.getAttribute('aria-labelledby')).toBe('body-heading')
    // The fallback name stays put: a surface carrying no such heading leaves
    // aria-labelledby resolving to nothing, and the name falls back to it.
    expect(byId('modal')?.getAttribute('aria-label')).toBe('Sign in')
  })

  it('drops aria-labelledby when the dialog has a visible title', () => {
    render(<HilosModal open title="Edit" aria-labelledby="body-heading" />)
    expect(byId('modal')?.getAttribute('aria-labelledby')).toBeNull()
    expect(byId('modal')?.getAttribute('aria-label')).toBe('Edit')
  })

  it('renders no modal-title heading when there is no title', () => {
    // An empty heading is a heading in the accessibility tree that names
    // nothing (docs/agents/frontend/accessibility.md).
    render(<HilosModal open aria-label="Sign in" />)
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

  it('stands wide when the surface asks for it, and keeps the confirm step narrow', () => {
    const view = render(<HilosModal open confirmOnClose />)
    fireEvent.click(byId('modal-close') as Element)

    let dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs).toHaveLength(2)
    dialogs.forEach((dialog) => {
      expect(dialog.classList.contains('modal-lg')).toBe(false)
    })

    view.rerender(<HilosModal open confirmOnClose size="wide" />)
    dialogs = document.querySelectorAll('.modal-dialog')
    expect(dialogs[0]?.classList.contains('modal-lg')).toBe(true)
    expect(dialogs[1]?.classList.contains('modal-lg')).toBe(false)
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

  it('is not busy without the input: the close button is enabled', () => {
    render(<HilosModal open />)
    expect(closeButton()?.disabled).toBe(false)
  })

  it('refuses every close gesture while busy and closes again once idle', () => {
    const closes: string[] = []
    const modal = (busy?: boolean) => (
      <HilosModal
        open
        confirmOnClose
        busy={busy}
        onClose={() => closes.push('close')}
        actions={({ requestClose }) => (
          <button
            type="button"
            data-id="footer-cancel"
            onClick={() => requestClose()}
          >
            Cancel
          </button>
        )}
      />
    )
    const view = render(modal())
    view.rerender(modal(true))

    const close = closeButton()
    expect(close?.disabled).toBe(true)
    expect(close?.classList.contains('btn-close')).toBe(true)
    expect(close?.getAttribute('aria-label')).toBe('Close')

    fireEvent.click(close as Element)
    fireEvent.keyDown(byId('modal') as Element, { key: 'Escape' })
    fireEvent.click(byId('modal') as Element)
    fireEvent.click(byId('footer-cancel') as Element)

    expect(closes).toEqual([])
    expect(byId('modal-confirm')).toBeNull()
    expect(byId('modal')).not.toBeNull()

    view.rerender(modal(false))
    expect(closeButton()?.disabled).toBe(false)

    // Idle, the dirty draft goes through the same Discard changes? step.
    fireEvent.click(closeButton() as Element)
    expect(byId('modal-confirm')).not.toBeNull()
    expect(closes).toEqual([])

    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(closes).toEqual(['close'])
  })

  it('closes on the close button again once busy clears, with no confirm guard', () => {
    let closes = 0
    const modal = (busy: boolean) => (
      <HilosModal
        open
        busy={busy}
        onClose={() => {
          closes += 1
        }}
      />
    )
    const view = render(modal(true))
    fireEvent.click(closeButton() as Element)
    expect(closes).toBe(0)

    view.rerender(modal(false))
    // Nothing refused while busy is replayed when it clears.
    expect(closes).toBe(0)

    fireEvent.click(closeButton() as Element)
    expect(closes).toBe(1)
  })

  it('outranks closeOnEsc and closeOnBackdrop while busy', () => {
    let closes = 0
    const modal = (busy: boolean) => (
      <HilosModal
        open
        busy={busy}
        closeOnEsc
        closeOnBackdrop
        onClose={() => {
          closes += 1
        }}
      />
    )
    const view = render(modal(true))
    fireEvent.keyDown(byId('modal') as Element, { key: 'Escape' })
    fireEvent.click(byId('modal') as Element)
    expect(closes).toBe(0)

    view.rerender(modal(false))
    fireEvent.keyDown(byId('modal') as Element, { key: 'Escape' })
    expect(closes).toBe(1)
    fireEvent.click(byId('modal') as Element)
    expect(closes).toBe(2)
  })

  it('keeps the draft nodes and an open confirm step when busy flips', () => {
    const modal = (busy: boolean) => (
      <HilosModal open confirmOnClose busy={busy}>
        <input data-autofocus data-id="field" />
      </HilosModal>
    )
    const view = render(modal(false))
    const field = byId('field') as HTMLInputElement
    field.value = 'draft'

    view.rerender(modal(true))
    expect(byId('field')).toBe(field)
    expect(field.matches(':disabled')).toBe(true)
    // jsdom does not reproduce the browser's focus fixup on disabling a field.
    view.rerender(modal(false))
    expect(byId('field')).toBe(field)
    expect(field.matches(':disabled')).toBe(false)
    expect((byId('field') as HTMLInputElement).value).toBe('draft')

    fireEvent.click(closeButton() as Element)
    const confirm = byId('modal-confirm')
    expect(document.activeElement).toBe(confirm)

    view.rerender(modal(true))
    fireEvent.keyDown(confirm as Element, { key: 'Escape' })
    fireEvent.click(byId('modal-confirm-discard') as Element)

    expect(byId('modal-confirm')).toBe(confirm)
    expect(document.activeElement).toBe(confirm)
    expect((byId('field') as HTMLInputElement).value).toBe('draft')
  })

  it('leaves the disabled close button and body out of the Tab cycle', () => {
    const modal = (busy: boolean) => (
      <HilosModal
        open
        busy={busy}
        actions={() => (
          <button type="button" data-id="save">
            Save
          </button>
        )}
      >
        <input data-autofocus data-id="field" />
      </HilosModal>
    )
    const view = render(modal(false))
    const save = byId('save') as HTMLElement

    save.focus()
    fireEvent.keyDown(save, { key: 'Tab' })
    expect(document.activeElement).toBe(closeButton())

    view.rerender(modal(true))
    save.focus()
    fireEvent.keyDown(save, { key: 'Tab' })
    expect(document.activeElement).toBe(save)

    fireEvent.keyDown(save, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(save)
  })

  it('closes when the parent sets it closed while busy, releasing scroll and focus', () => {
    const opener = document.createElement('button')
    document.body.appendChild(opener)
    opener.focus()

    const modal = (open: boolean) => (
      <HilosModal open={open} busy>
        <input data-autofocus data-id="field" />
      </HilosModal>
    )
    const view = render(modal(true))
    expect(document.activeElement).toBe(byId('modal'))
    expect(document.body.classList.contains('modal-open')).toBe(true)

    view.rerender(modal(false))

    expect(byId('modal')).toBeNull()
    expect(document.querySelector('.modal-backdrop')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(document.activeElement).toBe(opener)
    opener.remove()
  })
})

describe('HilosModal busy body', () => {
  it('keeps Tab on the root when busy leaves no available controls', () => {
    render(
      <HilosModal open busy>
        <input data-autofocus />
        <select defaultValue="a">
          <option value="a">A</option>
        </select>
      </HilosModal>,
    )
    const root = byId('modal')!
    expect(document.activeElement).toBe(root)
    for (const shiftKey of [false, true]) {
      expect(fireEvent.keyDown(root, { key: 'Tab', shiftKey })).toBe(false)
      expect(document.activeElement).toBe(root)
    }
  })

  it('keeps a permanent fieldset and every field draft through idle, busy and refusal', () => {
    const modal = (busy: boolean) => (
      <HilosModal open busy={busy}>
        <p data-id="readable">The draft stays readable.</p>
        <form data-id="draft-form">
          <input data-id="text" defaultValue="Original" />
          <select data-id="select" defaultValue="a">
            <option value="a">A</option>
            <option value="b">B</option>
          </select>
          <textarea data-id="textarea" defaultValue="Original" />
          <input data-id="check" type="checkbox" />
          <input data-id="radio-a" type="radio" name="choice" />
          <input data-id="radio-b" type="radio" name="choice" />
          <input data-id="independent" disabled defaultValue="Unchanged" />
        </form>
      </HilosModal>
    )
    const view = render(modal(false))
    const root = byId('modal')!
    const scope = byId('modal-interaction-scope')!
    const body = root.querySelector('.modal-body')!
    const fieldset = body.querySelector('fieldset')!
    const form = byId('draft-form')!
    const text = byId('text') as HTMLInputElement
    const select = byId('select') as HTMLSelectElement
    const textarea = byId('textarea') as HTMLTextAreaElement
    const check = byId('check') as HTMLInputElement
    const radios = ['radio-a', 'radio-b'].map(
      (id) => byId(id) as HTMLInputElement,
    )
    const fields = [text, select, textarea, check, ...radios]
    expect(fieldset.className).toBe('m-0 p-0 border-0')
    expect(fieldset.parentElement).toBe(body)
    expect(scope.contains(fieldset)).toBe(true)
    fields.forEach((field) => expect(field.matches(':disabled')).toBe(false))
    fireEvent.change(text, { target: { value: 'Keep my draft' } })
    text.setSelectionRange(2, 6)
    fireEvent.change(select, { target: { value: 'b' } })
    fireEvent.change(textarea, { target: { value: 'Keep my notes' } })
    fireEvent.click(check)
    fireEvent.click(radios[1]!)

    for (const busy of [true, false]) {
      view.rerender(modal(busy))
      expect(byId('modal')).toBe(root)
      expect(byId('modal-interaction-scope')).toBe(scope)
      expect(body.querySelector('fieldset')).toBe(fieldset)
      expect(byId('draft-form')).toBe(form)
      for (const field of fields) {
        expect(byId(field.dataset.id!)).toBe(field)
        expect(field.matches(':disabled')).toBe(busy)
        expect(field.disabled).toBe(false)
      }
      expect(byId('independent')?.matches(':disabled')).toBe(true)
      expect(text.value).toBe('Keep my draft')
      expect([text.selectionStart, text.selectionEnd]).toEqual([2, 6])
      expect(select.value).toBe('b')
      expect(textarea.value).toBe('Keep my notes')
      expect(check.checked).toBe(true)
      expect(radios.map((radio) => radio.checked)).toEqual([false, true])
      expect(byId('readable')?.textContent).toBe('The draft stays readable.')
      expect(root.querySelector('[inert], [aria-hidden="true"]')).toBeNull()
    }
  })

  it('disables Copy and owner-bound ordinary actions without wrapping the footer', async () => {
    recordingClipboard()
    const cancel = vi.fn()
    const modal = (busy: boolean) => (
      <HilosModal
        open
        busy={busy}
        copyText="Draft to copy"
        actions={() => (
          <>
            <button data-id="cancel" disabled={busy} onClick={cancel}>
              Cancel
            </button>
            <LoadingButton data-id="save" loading={busy}>
              Save
            </LoadingButton>
          </>
        )}
      />
    )
    const view = render(modal(false))
    const footer = document.querySelector('.modal-footer')!
    const copy = byId('modal-copy')!
    const save = byId('save')!
    view.rerender(modal(true))
    expect(document.querySelector('.modal-footer')).toBe(footer)
    for (const button of [copy, byId('cancel')!]) {
      expect(button.matches(':disabled')).toBe(true)
      fireEvent.click(button)
    }
    await settled()
    expect(written).toEqual([])
    expect(cancel).not.toHaveBeenCalled()
    expect(save.matches(':disabled')).toBe(false)
    expect(save.getAttribute('aria-busy')).toBe('true')
    view.rerender(modal(false))
    expect(copy.matches(':disabled')).toBe(false)
    expect(byId('cancel')?.matches(':disabled')).toBe(false)
    fireEvent.click(copy)
    fireEvent.click(byId('cancel')!)
    await settled()
    expect(written).toEqual(['Draft to copy'])
    expect(cancel).toHaveBeenCalledOnce()
  })

  it('gives body priority and live busy without restarting the dialog lifecycle', () => {
    const activate = vi.spyOn(FocusTrap.prototype, 'activate')
    const release = vi.spyOn(FocusTrap.prototype, 'release')
    const values: boolean[] = []
    const modal = (busy: boolean) => (
      <HilosModal
        open
        busy={busy}
        header={<h2 data-id="header">Custom header</h2>}
        body={({ busy: bodyBusy }) => {
          values.push(bodyBusy)
          return (
            <>
              <fieldset disabled={bodyBusy}>
                <input data-id="body-field" defaultValue="Draft" />
              </fieldset>
              <LoadingButton data-id="body-save" loading={bodyBusy}>
                Save
              </LoadingButton>
            </>
          )
        }}
        actions={() => <button data-id="footer">Footer</button>}
      >
        <span data-id="fallback">Unused fallback</span>
      </HilosModal>
    )
    const view = render(modal(false))
    const root = byId('modal')!
    const body = root.querySelector('.modal-body')!
    const header = byId('header')!
    const footer = byId('footer')!
    const input = byId('body-field')!
    const layers = layerDepths('.hilos-modal-layer')
    const activations = activate.mock.calls.length
    const releases = release.mock.calls.length
    body.scrollTop = 83
    expect(byId('fallback')).toBeNull()
    expect(values.at(-1)).toBe(false)
    expect(body.querySelectorAll('fieldset')).toHaveLength(1)
    expect(input.matches(':disabled')).toBe(false)
    for (const busy of [true, false]) {
      view.rerender(modal(busy))
      expect(values.at(-1)).toBe(busy)
      expect(byId('modal')).toBe(root)
      expect(byId('header')).toBe(header)
      expect(byId('footer')).toBe(footer)
      expect(byId('body-field')).toBe(input)
      expect(input.matches(':disabled')).toBe(busy)
      expect(byId('body-save')?.matches(':disabled')).toBe(false)
      expect(footer.matches(':disabled')).toBe(false)
      expect(body.scrollTop).toBe(83)
      expect(layerDepths('.hilos-modal-layer')).toEqual(layers)
      expect(document.body.classList.contains('modal-open')).toBe(true)
      expect(activate.mock.calls).toHaveLength(activations)
      expect(release.mock.calls).toHaveLength(releases)
    }
  })

  it('keeps a real body LoadingButton focused and blocks repeats before the 300ms spinner', () => {
    vi.useFakeTimers()
    const sends = vi.fn()
    const modal = (busy: boolean) => (
      <HilosModal
        open
        busy={busy}
        body={({ busy: bodyBusy }) => (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              sends()
              view.rerender(modal(true))
            }}
          >
            <fieldset disabled={bodyBusy}>
              <input data-id="field" defaultValue="Draft" />
            </fieldset>
            <LoadingButton type="submit" data-id="send" loading={bodyBusy}>
              Send
            </LoadingButton>
          </form>
        )}
      />
    )
    const view = render(modal(false))
    const send = byId('send')!
    send.focus()
    fireEvent.click(send)
    expect(sends).toHaveBeenCalledOnce()
    for (const elapsed of [0, 299, 1]) {
      act(() => vi.advanceTimersByTime(elapsed))
      expect(byId('send')).toBe(send)
      expect(document.activeElement).toBe(send)
      expect(send.matches(':disabled')).toBe(false)
      expect(send.getAttribute('aria-busy')).toBe('true')
      expect(send.getAttribute('aria-disabled')).toBe('true')
      expect(byId('field')?.matches(':disabled')).toBe(true)
      expect(byId('loading-button-spinner') !== null).toBe(elapsed === 1)
      expect(fireEvent.click(send)).toBe(false)
      expect(sends).toHaveBeenCalledOnce()
    }
    view.rerender(modal(false))
    expect(document.activeElement).toBe(send)
    expect(byId('loading-button-spinner')).toBeNull()
    expect(send.getAttribute('aria-busy')).toBeNull()
    expect(send.getAttribute('aria-disabled')).toBeNull()
    expect(byId('field')?.matches(':disabled')).toBe(false)
    fireEvent.click(send)
    expect(sends).toHaveBeenCalledTimes(2)
  })
})

describe('HilosModal focus after busy', () => {
  it.each([
    ['', false, 'field'],
    ['inner', false, 'field'],
    ['dialog', false, 'modal'],
    ['', true, 'modal'],
  ] as const)(
    'restores %s initial focus after commit with an independently disabled mark: %s',
    (initialFocus, disabled, target) => {
      const modal = (busy: boolean) => (
        <HilosModal open busy={busy} initialFocus={initialFocus}>
          <input data-id="field" data-autofocus disabled={disabled} />
        </HilosModal>
      )
      const view = render(modal(false))
      for (const focusFrom of ['root', 'body']) {
        view.rerender(modal(true))
        const root = byId('modal')!
        // Set the starting focus explicitly: browser focus fixup on entering
        // busy belongs to a separate browser scenario, not this jsdom test.
        root.focus()
        if (focusFrom === 'body') root.blur()
        expect(document.activeElement).toBe(
          focusFrom === 'root' ? root : document.body,
        )
        view.rerender(modal(false))
        expect(byId('field')?.matches(':disabled')).toBe(disabled)
        expect(document.activeElement).toBe(byId(target))
      }
    },
  )

  it('refocuses from no active element and skips a disconnected root', () => {
    const refocus = vi.spyOn(FocusTrap.prototype, 'refocus')
    const modal = (busy: boolean) => (
      <HilosModal open busy={busy}>
        <input data-id="field" data-autofocus />
      </HilosModal>
    )
    const view = render(modal(true))
    refocus.mockClear()
    const active = vi.spyOn(document, 'activeElement', 'get')
    active.mockReturnValue(null)
    view.rerender(modal(false))
    active.mockRestore()
    expect(refocus).toHaveBeenCalledExactlyOnceWith(byId('modal'), 'marked')
    expect(document.activeElement).toBe(byId('field'))
    view.rerender(modal(true))
    const root = byId('modal')!
    root.remove()
    refocus.mockClear()
    view.rerender(modal(false))
    expect(refocus).not.toHaveBeenCalled()
    document.body.appendChild(root)
  })

  it('does not refocus on idle rerenders or steal another choice inside or outside', () => {
    const modal = (busy: boolean, initialFocus: '' | 'inner' = '') => (
      <>
        <button data-id="outside">Outside</button>
        <HilosModal
          open
          busy={busy}
          initialFocus={initialFocus}
          actions={() => <button data-id="other">Other action</button>}
        >
          <input data-id="field" data-autofocus />
        </HilosModal>
      </>
    )
    const view = render(modal(false))
    const root = byId('modal')!
    root.focus()
    view.rerender(modal(false, 'inner'))
    expect(document.activeElement).toBe(root)
    for (const id of ['other', 'outside']) {
      view.rerender(modal(true))
      const choice = byId(id)!
      choice.focus()
      view.rerender(modal(false))
      expect(document.activeElement).toBe(choice)
    }
  })

  it('leaves focus in the upper modal when the lower request settles', () => {
    const tree = (busy: boolean, upper: boolean) => (
      <HilosModal open busy={busy} title="Lower">
        <input data-id="lower-field" data-autofocus />
        <HilosModal open={upper} title="Upper" initialFocus="dialog" />
      </HilosModal>
    )
    const view = render(tree(false, false))
    view.rerender(tree(true, true))
    const upper = document.querySelector<HTMLElement>('[aria-label="Upper"]')!
    expect(document.activeElement).toBe(upper)
    const layers = layerDepths('.hilos-modal-layer')
    view.rerender(tree(false, true))
    expect(document.activeElement).toBe(upper)
    expect(layerDepths('.hilos-modal-layer')).toEqual(layers)
  })

  it('keeps the opener when reopening and clearing a former busy state together', () => {
    const modal = (open: boolean, busy: boolean) => (
      <>
        <button data-id="opener">Open</button>
        <HilosModal open={open} busy={busy}>
          <input data-autofocus data-id="field" />
        </HilosModal>
      </>
    )
    const view = render(modal(false, true))
    const opener = byId('opener')!
    opener.focus()
    view.rerender(modal(true, false))
    expect(document.activeElement).toBe(byId('field'))
    view.rerender(modal(false, false))
    expect(document.activeElement).toBe(opener)
  })

  it.each(['modal-confirm', 'modal-confirm-cancel', 'modal'])(
    'does not refocus from %s while a confirm remains open',
    (id) => {
      const refocus = vi.spyOn(FocusTrap.prototype, 'refocus')
      const modal = (busy: boolean) => (
        <HilosModal open busy={busy} confirmOnClose>
          <input data-id="field" data-autofocus />
        </HilosModal>
      )
      const view = render(modal(false))
      fireEvent.click(closeButton()!)
      const confirm = byId('modal-confirm')!
      view.rerender(modal(true))
      const choice = byId(id)!
      choice.focus()
      refocus.mockClear()
      view.rerender(modal(false))
      expect(byId('modal-confirm')).toBe(confirm)
      expect(document.activeElement).toBe(choice)
      expect(refocus).not.toHaveBeenCalled()
    },
  )

  it.each(['close', 'unmount'])(
    'does not refocus a departed dialog on %s as busy clears',
    async (exit) => {
      const refocus = vi.spyOn(FocusTrap.prototype, 'refocus')
      const modal = (open: boolean, busy: boolean) => (
        <>
          <button data-id="opener">Open</button>
          <HilosModal open={open} busy={busy}>
            <input data-id="field" data-autofocus />
          </HilosModal>
        </>
      )
      const view = render(modal(false, false))
      const opener = byId('opener')!
      opener.focus()
      view.rerender(modal(true, true))
      const root = byId('modal')!
      expect(document.activeElement).toBe(root)
      refocus.mockClear()
      if (exit === 'close') view.rerender(modal(false, false))
      else view.unmount()
      await settled()
      expect(root.isConnected).toBe(false)
      expect(byId('modal')).toBeNull()
      expect(document.activeElement).toBe(
        exit === 'close' ? opener : document.body,
      )
      expect(refocus).not.toHaveBeenCalled()
      expect(document.body.classList.contains('modal-open')).toBe(false)
    },
  )
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

/** Mount the real portal and shell in StrictMode with live independent owner props. */
function connectedModal() {
  const feed = modalFeed()
  const clicks = vi.fn()
  const canceled = vi.fn()
  let requestClose = () => {}
  const state = {
    open: true,
    present: true,
    second: false,
    allow: false,
    busy: false,
    dirty: false,
    disabled: false,
    description: 'validation',
  }
  const tree = () => (
    <StrictMode>
      <HilosLayout
        connection={feed.connection}
        allowDisconnectedInteraction={state.allow}
        nav={
          <a href="#nav" data-id="modal-nav">
            Navigation
          </a>
        }
      >
        <LoadingButton opensWindow data-id="modal-open" onClick={clicks}>
          Open
        </LoadingButton>
        {state.present ? (
          <HilosModal
            open={state.open}
            busy={state.busy}
            confirmOnClose={state.dirty}
            title="Edit"
            copyText="technical details"
            onClose={canceled}
            header={
              <a href="#header" data-id="header-action" onClick={clicks}>
                Header action
              </a>
            }
            actions={({ requestClose: close }) => {
              requestClose = close
              return (
                <button type="button" data-id="modal-cancel" onClick={close}>
                  Cancel
                </button>
              )
            }}
          >
            <form data-id="modal-form" onSubmit={clicks}>
              <input
                data-autofocus
                data-id="modal-draft"
                disabled={state.disabled}
                aria-describedby={state.description}
                onBeforeInput={clicks}
                onDrop={clicks}
              />
              <input type="file" data-id="modal-file" />
              <select data-id="modal-choice" defaultValue="a">
                <option value="a">A</option>
                <option value="b">B</option>
              </select>
              <button type="button" data-id="body-action" onClick={clicks}>
                Body action
              </button>
              <a
                href="#action"
                role="button"
                data-id="body-link"
                onClick={clicks}
                onKeyDown={clicks}
              >
                Link action
              </a>
              <LoadingButton
                loading={state.busy}
                data-id="modal-save"
                onClick={clicks}
              >
                Save
              </LoadingButton>
            </form>
          </HilosModal>
        ) : null}
        {state.second ? (
          <HilosModal open title="Second" confirmOnClose />
        ) : null}
      </HilosLayout>
    </StrictMode>
  )
  const view = render(tree())
  return {
    view,
    feed,
    clicks,
    canceled,
    state,
    close: () => requestClose(),
    configure(patch: Partial<typeof state>): void {
      Object.assign(state, patch)
      view.rerender(tree())
    },
    push(value: ConnectionState): void {
      act(() => feed.push(value))
    },
  }
}

/** The required portal surface of the connection tests. */
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
  it('blocks delegated React capture in the page, portal, nested dialog and confirm while leaving shell navigation available', () => {
    const feed = modalFeed()
    const captured = vi.fn()
    const controlCapture = vi.fn()
    const navigation = vi.fn()
    let closeNested = () => {}
    render(
      <HilosLayout
        connection={feed.connection}
        nav={
          <a
            href="#navigation"
            data-id="capture-nav"
            onClickCapture={navigation}
          >
            Navigation
          </a>
        }
      >
        <section onClickCapture={captured} onKeyDownCapture={captured}>
          <a
            href="#page"
            data-id="capture-page"
            onClickCapture={controlCapture}
            onKeyDownCapture={controlCapture}
          >
            Page action
          </a>
          <div
            role="button"
            tabIndex={0}
            data-id="capture-page-role"
            onClickCapture={controlCapture}
            onKeyDownCapture={controlCapture}
          >
            Page role
          </div>
          <HilosModal open title="Capture dialog">
            <a
              href="#body"
              data-id="capture-body"
              onClickCapture={controlCapture}
              onKeyDownCapture={controlCapture}
            >
              Body action
            </a>
            <div
              role="button"
              tabIndex={0}
              data-id="capture-body-role"
              onClickCapture={controlCapture}
              onKeyDownCapture={controlCapture}
            >
              Body role
            </div>
            <HilosModal
              open
              title="Nested capture dialog"
              confirmOnClose
              actions={({ requestClose }) => {
                closeNested = requestClose
                return null
              }}
            >
              <a
                href="#nested"
                data-id="capture-nested"
                onClickCapture={controlCapture}
                onKeyDownCapture={controlCapture}
              >
                Nested action
              </a>
            </HilosModal>
          </HilosModal>
        </section>
      </HilosLayout>,
    )
    act(() => closeNested())
    const confirm = modalElement('modal-confirm')
    const targets = [
      'capture-page',
      'capture-page-role',
      'capture-body',
      'capture-body-role',
      'capture-nested',
      'modal-confirm-cancel',
    ].map(modalElement)
    const activate = (target: HTMLElement) => {
      // Capture observes all three gestures without preventing any itself.
      fireEvent.keyDown(target, { key: 'Enter' })
      fireEvent.keyDown(target, { key: ' ' })
      fireEvent.click(target)
    }
    for (const target of targets) {
      activate(target)
      expect(captured).toHaveBeenCalledTimes(3)
      expect(controlCapture).toHaveBeenCalledTimes(
        target.dataset.id === 'modal-confirm-cancel' ? 0 : 3,
      )
      captured.mockClear()
      controlCapture.mockClear()
    }
    // The connected cancel gesture closed the confirm: reopen the same step.
    act(() => closeNested())
    targets[targets.length - 1] = modalElement('modal-confirm-cancel')
    act(() => feed.push('disconnected'))
    for (const target of targets) {
      activate(target)
      expect(captured).not.toHaveBeenCalled()
      expect(controlCapture).not.toHaveBeenCalled()
    }
    fireEvent.click(modalElement('capture-nav'))
    expect(navigation).toHaveBeenCalledOnce()
    expect(captured).not.toHaveBeenCalled()
    const blockedConfirm = modalElement('modal-confirm')
    expect(blockedConfirm).not.toBe(confirm)
    act(() => feed.push('connected'))
    expect(modalElement('modal-confirm')).toBe(blockedConfirm)
    expect(captured).not.toHaveBeenCalled()
    for (const target of targets) {
      activate(target)
      expect(captured).toHaveBeenCalledTimes(3)
      expect(controlCapture).toHaveBeenCalledTimes(
        target.dataset.id === 'modal-confirm-cancel' ? 0 : 3,
      )
      captured.mockClear()
      controlCapture.mockClear()
    }
  })

  it('keeps the portal draft, selection, files, scroll and lifecycle through every outage state', async () => {
    const activate = vi.spyOn(FocusTrap.prototype, 'activate')
    recordingClipboard()
    const shell = connectedModal()
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
    expect(document.querySelector('main')?.contains(root)).toBe(false)
    for (const state of [
      'connecting',
      'reconnecting',
      'disconnected',
    ] as const) {
      act(() => {
        shell.feed.push(state)
        shell.close()
        expect(shell.canceled).not.toHaveBeenCalled()
        expect(field.matches(':disabled')).toBe(true)
        expect(document.activeElement).toBe(root)
      })
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
        fireEvent.click(modalElement(id))
      for (const key of ['Enter', ' '])
        fireEvent.keyDown(modalElement('body-link'), { key })
      for (const type of ['beforeinput', 'drop']) {
        const event = new Event(type, { bubbles: true, cancelable: true })
        fireEvent(field, event)
        expect(event.defaultPrevented).toBe(true)
      }
      fireEvent.submit(modalElement('modal-form'))
      fireEvent.keyDown(root, { key: 'Escape' })
      fireEvent.click(root)
      fireEvent.keyDown(root, { key: 'Tab' })
      await settled()
      expectConnectionDescription(root, true)
      expect(shell.clicks).not.toHaveBeenCalled()
      expect(shell.canceled).not.toHaveBeenCalled()
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
    shell.push('connected')
    expectConnectionDescription(root, false)
    expect(field.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(field)
    expect(shell.clicks).not.toHaveBeenCalled()
    fireEvent.click(modalElement('body-action'))
    expect(shell.clicks).toHaveBeenCalledTimes(1)
    act(() => shell.close())
    expect(shell.canceled).toHaveBeenCalledTimes(1)
  })

  it('holds an existing confirm, its local description and focus until a fresh gesture', () => {
    const shell = connectedModal()
    shell.configure({ dirty: true })
    act(() => shell.close())
    const confirm = modalElement('modal-confirm')
    expectConnectionDescription(modalElement('modal'), false)
    expectConnectionDescription(confirm, false)
    modalElement('modal-confirm-discard').focus()
    shell.push('disconnected')
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
      fireEvent.click(modalElement(id))
    fireEvent.keyDown(confirm, { key: 'Escape' })
    act(() => shell.close())
    expect(modalElement('modal-confirm')).toBe(confirm)
    shell.push('connected')
    expect(modalElement('modal-confirm')).toBe(confirm)
    expectConnectionDescription(modalElement('modal'), false)
    expectConnectionDescription(confirm, false)
    expect(confirm.hasAttribute('aria-describedby')).toBe(false)
    fireEvent.click(modalElement('modal-confirm-cancel'))
    expect(byId('modal-confirm')).toBeNull()
    act(() => shell.close())
    fireEvent.click(modalElement('modal-confirm-discard'))
    expect(shell.canceled).toHaveBeenCalledTimes(1)
  })

  it('preserves live owner disabled, descriptions and busy without taking a different focus choice', async () => {
    const shell = connectedModal()
    shell.push('disconnected')
    shell.configure({
      disabled: true,
      description: 'new-validation look-only',
      busy: true,
    })
    await settled()
    const field = modalElement('modal-draft')
    expect(field.getAttribute('aria-describedby')).toContain(
      'new-validation look-only',
    )
    modalElement('modal-nav').focus()
    shell.push('connected')
    expect(document.activeElement).toBe(modalElement('modal-nav'))
    expect(field.matches(':disabled')).toBe(true)
    expect(field.getAttribute('aria-describedby')).toBe(
      'new-validation look-only',
    )
    expect(closeButton()?.disabled).toBe(true)
    expect(
      (modalElement('modal-interaction-scope') as HTMLFieldSetElement).disabled,
    ).toBe(false)
    act(() => shell.close())
    expect(shell.canceled).not.toHaveBeenCalled()
    shell.configure({ busy: false })
    expect(field.matches(':disabled')).toBe(true)
  })

  it('honors opt-out and external close and cleans each StrictMode root on replacement and unmount', () => {
    const shell = connectedModal()
    const old = modalElement('modal')
    shell.push('disconnected')
    shell.configure({ allow: true })
    expectConnectionDescription(modalElement('modal'), false)
    expect(modalElement('modal-draft').matches(':disabled')).toBe(false)
    fireEvent.click(modalElement('body-action'))
    expect(shell.clicks).toHaveBeenCalledTimes(1)
    shell.configure({ allow: false, open: false })
    expect(old.hasAttribute('data-hilos-interaction-region')).toBe(false)
    expect(document.body.classList.contains('modal-open')).toBe(false)
    expect(shell.canceled).not.toHaveBeenCalled()
    shell.configure({ open: true })
    expect(modalElement('modal')).not.toBe(old)
    expect(modalElement('modal-draft').matches(':disabled')).toBe(true)
    shell.configure({ present: false })
    shell.configure({ present: true })
    shell.view.unmount()
    expect(
      [...shell.feed.listeners.values()].every((set) => set.size === 0),
    ).toBe(true)
    shell.push('connected')
    expect(old.hasAttribute('aria-describedby')).toBe(false)
  })

  it('keeps a standalone modal interactive beside an unavailable shell', () => {
    const shell = connectedModal()
    shell.push('disconnected')
    const closed = vi.fn()
    render(<HilosModal open title="Standalone" onClose={closed} />)
    const root = document.querySelector<HTMLElement>(
      '[aria-label="Standalone"]',
    )!
    const close = root.querySelector<HTMLButtonElement>(
      '[data-id="modal-close"]',
    )!
    expectConnectionDescription(root, false)
    expect(close.matches(':disabled')).toBe(false)
    expect(root.hasAttribute('aria-describedby')).toBe(false)
    fireEvent.click(close)
    expect(closed).toHaveBeenCalledTimes(1)
    expect(shell.canceled).not.toHaveBeenCalled()
  })

  it('gives concurrent instances and their confirm layers four different local descriptions', () => {
    const shell = connectedModal()
    shell.configure({ dirty: true })
    act(() => shell.close())
    shell.configure({ second: true })
    fireEvent.click(
      document.querySelector('[aria-label="Second"] [data-id="modal-close"]')!,
    )
    shell.push('disconnected')
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
