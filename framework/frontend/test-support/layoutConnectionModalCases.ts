import { hilosToasts } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { layoutChromeFeed, layoutChromeRouter } from './layoutChromeFixtures.js'
import type {
  LayoutConnectionInput,
  LayoutConnectionView,
} from './layoutConnectionFixtures.js'

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (element === null)
    throw new Error(`Missing connection modal control: ${id}`)
  return element
}

/** Locate this host's real modal across its native projection, teleport or portal. */
function modal(root: HTMLElement): HTMLElement {
  const dialog = get(
    root.ownerDocument,
    'connection-modal-body',
  ).closest<HTMLElement>('[role="dialog"]')
  if (dialog === null) throw new Error('Connection modal body has no dialog')
  return dialog
}

/** Retained real modal draft and exact focus return through connection changes. */
export function registerLayoutConnectionModalCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect'>,
  mount: (input: LayoutConnectionInput) => Promise<LayoutConnectionView>,
): void {
  const { describe, it, expect } = runner
  describe(`HilosLayout connection modal on ${sdk}`, () => {
    it('C09 retains the open modal, draft and exact field focus on every reconnect', async () => {
      let view: LayoutConnectionView | undefined
      const errors: unknown[] = []
      try {
        const feed = layoutChromeFeed()
        view = await mount({
          connection: feed.connection,
          router: layoutChromeRouter().router,
          modalOpen: false,
        })
        await view.update({ modalOpen: true })
        const pageScope = get<HTMLFieldSetElement>(
          view.root,
          'page-interaction-scope',
        )
        const dialog = modal(view.root)
        const modalScope = get<HTMLFieldSetElement>(
          dialog,
          'modal-interaction-scope',
        )
        const field = get<HTMLInputElement>(dialog, 'modal-draft')
        const document = view.root.ownerDocument
        field.value = 'Keep this modal draft'
        field.setSelectionRange(2, 7)
        expect(modalScope.contains(field)).toBe(true)
        for (const state of ['disconnected', 'reconnecting'] as const) {
          field.focus()
          expect(document.activeElement).toBe(field)
          await view.flush(() => {
            feed.state(state)
            expect(pageScope.disabled).toBe(true)
            expect(modalScope.disabled).toBe(true)
            expect(document.activeElement).toBe(dialog)
          })
          expect(modal(view.root)).toBe(dialog)
          expect(get(modal(view.root), 'modal-draft')).toBe(field)
          expect(field.value).toBe('Keep this modal draft')
          expect([field.selectionStart, field.selectionEnd]).toEqual([2, 7])
          expect(dialog.closest('[inert], [aria-hidden="true"]')).toBeNull()
          await view.flush(() => {
            feed.state('connected')
            expect(pageScope.disabled).toBe(false)
            expect(modalScope.disabled).toBe(false)
            expect(field.matches(':disabled')).toBe(false)
            expect(document.activeElement).toBe(field)
          })
          expect(modal(view.root)).toBe(dialog)
          expect(get(modal(view.root), 'modal-draft')).toBe(field)
          expect(field.value).toBe('Keep this modal draft')
        }
      } catch (error) {
        errors.push(error)
      } finally {
        try {
          await view?.unmount()
        } catch (error) {
          errors.push(error)
        }
        try {
          hilosToasts.clear()
        } catch (error) {
          errors.push(error)
        }
      }
      if (errors.length === 1) throw errors[0]
      if (errors.length > 1)
        throw new AggregateError(
          errors,
          'Connection modal case and cleanup failed',
        )
    })
  })
}
