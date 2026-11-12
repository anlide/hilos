import { HilosPages } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { readLayoutPublicStatusDay } from './layoutPublicStatusColdCases.js'
import {
  withLayoutPublicStatus,
  type MountLayoutPublicStatus,
} from './layoutPublicStatusFixtures.js'

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing public Status element: ${id}`)
  return element
}

/** Public reading releases only its page, preserving the caller's shell policy. */
export function registerLayoutPublicStatusInteractionCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>,
  mount: MountLayoutPublicStatus,
): void {
  const { describe, it, expect, vi } = runner

  describe(`HilosLayout public Status interaction on ${sdk}`, () => {
    it('POLICY restores the caller interaction policy on a rapid ordinary round trip', async () => {
      await withLayoutPublicStatus(
        vi,
        mount,
        { state: 'disconnected' },
        async (scope) => {
          const { view, route, commit } = scope
          await readLayoutPublicStatusDay(expect, scope)
          for (const allow of [false, true, false]) {
            await commit(() => view.update({ allow }))
            await commit(() =>
              route.set({ page: 'ordinary', params: {}, admin: false }),
            )
            expect(
              get<HTMLFieldSetElement>(view.root, 'page-interaction-scope')
                .disabled,
            ).toBe(!allow)
            await commit(() =>
              route.set({
                page: HilosPages.PUBLIC_STATUS,
                params: {},
                admin: false,
              }),
            )
            await readLayoutPublicStatusDay(expect, scope)
          }
        },
      )
    })

    it('DIALOG keeps a shell action dialog blocked while public day controls are released', async () => {
      const mutate = vi.fn()
      await withLayoutPublicStatus(
        vi,
        mount,
        { state: 'disconnected', onSave: mutate },
        async (scope) => {
          const { view, feed, commit } = scope
          await readLayoutPublicStatusDay(expect, scope)
          await commit(() => view.update({ modalOpen: true }))
          // This marker belongs to the user-slot body, including its portal.
          const action = get<HTMLButtonElement>(
            view.root.ownerDocument,
            'shell-dialog-action',
          )
          const modalScope = action.closest<HTMLFieldSetElement>(
            '[data-id="modal-interaction-scope"]',
          )!
          expect(modalScope.disabled).toBe(true)
          expect(action.matches(':disabled')).toBe(true)
          await commit(() => action.click())
          expect(mutate).not.toHaveBeenCalled()
          await commit(() => feed.state('connected'))
          expect(modalScope.disabled).toBe(false)
          expect(action.matches(':disabled')).toBe(false)
          await commit(() => action.click())
          expect(mutate).toHaveBeenCalledTimes(1)
        },
      )
    })
  })
}
