import {
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  hilosToasts,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { layoutChromeFeed, layoutChromeRouter } from './layoutChromeFixtures.js'
import type {
  LayoutConnectionInput,
  LayoutConnectionView,
} from './layoutConnectionFixtures.js'

type LayoutConnectionRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (element === null)
    throw new Error(`Missing connection page control: ${id}`)
  return element
}

/** Release every retained root, preserving scenario and native cleanup failures. */
async function release(
  roots: readonly LayoutConnectionView[],
  failure: { error: unknown } | undefined,
): Promise<void> {
  const results = await Promise.allSettled(roots.map((root) => root.unmount()))
  hilosToasts.clear()
  const errors = failure === undefined ? [] : [failure.error]
  for (const result of results)
    if (result.status === 'rejected') errors.push(result.reason)
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(
      errors,
      'Connection page scenario and cleanup failed',
    )
}

/** One page-preservation and source-lifetime contract on all native SDK mounts. */
export function registerLayoutConnectionPageCases(
  sdk: string,
  runner: LayoutConnectionRunner,
  mount: (input: LayoutConnectionInput) => Promise<LayoutConnectionView>,
): void {
  const { describe, it, expect } = runner
  describe(`HilosLayout connection page on ${sdk}`, () => {
    it('C01 keeps the main, fieldset, draft, file, choice and scroll through every unavailable state', async () => {
      const roots: LayoutConnectionView[] = []
      let failure: { error: unknown } | undefined
      try {
        const feed = layoutChromeFeed()
        const view = await mount({
          connection: feed.connection,
          router: layoutChromeRouter().router,
        })
        roots.push(view)
        const main = view.root.querySelector('main')!
        const fieldset = get<HTMLFieldSetElement>(
          view.root,
          'page-interaction-scope',
        )
        const draft = get<HTMLInputElement>(view.root, 'draft')
        const file = get<HTMLInputElement>(view.root, 'file')
        const files = Object.freeze([new File(['draft'], 'draft.txt')])
        Object.defineProperty(file, 'files', { value: files })
        draft.value = 'Keep this draft'
        draft.setSelectionRange(2, 6)
        get<HTMLSelectElement>(view.root, 'choice').value = 'b'
        main.scrollTop = 137
        for (const state of [
          'connecting',
          'reconnecting',
          'disconnected',
        ] as const) {
          await view.flush(() => {
            feed.state(state)
            expect(fieldset.disabled).toBe(true)
          })
          expect(get(view.root, 'draft')).toBe(draft)
          expect(draft.closest('fieldset')).toBe(fieldset)
          expect(get(view.root, 'page-open').closest('fieldset')).toBe(fieldset)
          expect(draft.matches(':disabled')).toBe(true)
          expect(get(view.root, 'page-open').matches(':disabled')).toBe(true)
          await view.flush(() => {
            get(view.root, 'page-open').click()
            get(view.root, 'page-link').click()
          })
          expect(view.clicks).not.toHaveBeenCalled()
          expect(
            get(view.root, 'page-view').getAttribute('aria-describedby'),
          ).toBe(
            `validation ${HILOS_VIEW_MODE_STRIP_TEXT_ID} ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
          )
          expect(draft.getAttribute('aria-describedby')).toBe(
            `validation ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
          )
          expect(
            view.root.querySelectorAll(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`),
          ).toHaveLength(1)
        }
        await view.update({ disabled: true, description: 'new-validation' })
        await view.flush(() => {
          feed.state('connected')
          feed.rtStaleness({ stale: true, since: undefined })
        })
        expect(view.root.querySelector('main')).toBe(main)
        expect(get(view.root, 'page-interaction-scope')).toBe(fieldset)
        expect(fieldset.disabled).toBe(false)
        expect(draft.disabled).toBe(true)
        expect(get<HTMLButtonElement>(view.root, 'page-view').disabled).toBe(
          true,
        )
        expect(
          get(view.root, 'page-view').getAttribute('aria-describedby'),
        ).toBe(`validation ${HILOS_VIEW_MODE_STRIP_TEXT_ID}`)
        expect(draft.getAttribute('aria-describedby')).toBe('new-validation')
        expect(draft.value).toBe('Keep this draft')
        expect([draft.selectionStart, draft.selectionEnd]).toEqual([2, 6])
        expect(file.files).toBe(files)
        expect(get<HTMLSelectElement>(view.root, 'choice').value).toBe('b')
        expect(main.scrollTop).toBe(137)
        expect(view.clicks).not.toHaveBeenCalled()
        await view.flush(() => get(view.root, 'page-open').click())
        expect(view.clicks).toHaveBeenCalledTimes(1)
      } catch (error) {
        failure = { error }
      } finally {
        await release(roots, failure)
      }
    })

    it('C03 updates opt-out and the connection live, releases replaced sources and remounts cleanly', async () => {
      const roots: LayoutConnectionView[] = []
      let failure: { error: unknown } | undefined
      try {
        const old = layoutChromeFeed()
        old.state('disconnected')
        const router = layoutChromeRouter().router
        const view = await mount({ connection: old.connection, router })
        roots.push(view)
        const fieldset = get<HTMLFieldSetElement>(
          view.root,
          'page-interaction-scope',
        )
        await view.update({ allow: true })
        expect(fieldset.disabled).toBe(false)
        expect(view.interaction.unavailable.get()).toBe(true)
        const next = layoutChromeFeed()
        await view.update({ allow: false, connection: next.connection })
        expect(fieldset.disabled).toBe(false)
        await view.flush(() => old.state('connecting'))
        expect(fieldset.disabled).toBe(false)
        expect(old.listenerCount('state')).toBe(0)
        await view.flush(() => next.state('reconnecting'))
        expect(fieldset.disabled).toBe(true)
        await view.unmount()
        expect(next.listenerCounts().every((count) => count === 0)).toBe(true)
        const remounted = await mount({ connection: next.connection, router })
        roots.push(remounted)
        expect(
          get<HTMLFieldSetElement>(remounted.root, 'page-interaction-scope')
            .disabled,
        ).toBe(true)
      } catch (error) {
        failure = { error }
      } finally {
        await release(roots, failure)
      }
    })
  })
}
