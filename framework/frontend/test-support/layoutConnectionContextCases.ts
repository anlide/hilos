import { HilosPages, hilosToasts } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { layoutChromeFeed, layoutChromeRouter } from './layoutChromeFixtures.js'
import type {
  LayoutConnectionInput,
  LayoutConnectionStandalone,
  LayoutConnectionView,
} from './layoutConnectionFixtures.js'

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (element === null)
    throw new Error(`Missing connection context control: ${id}`)
  return element
}

/** Find the real modal through this host's body marker across native portals. */
function modal(root: HTMLElement): HTMLElement {
  const dialog = get(
    root.ownerDocument,
    'connection-modal-body',
  ).closest<HTMLElement>('[role="dialog"]')
  if (dialog === null) throw new Error('Connection context body has no dialog')
  return dialog
}

/** Release every owned native lifetime even after a scenario or teardown failure. */
async function release(
  roots: readonly Pick<LayoutConnectionStandalone, 'unmount'>[],
  errors: unknown[],
): Promise<void> {
  for (const root of [...roots].reverse()) {
    try {
      await root.unmount()
    } catch (error) {
      errors.push(error)
    }
  }
  try {
    hilosToasts.clear()
  } catch (error) {
    errors.push(error)
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(
      errors,
      'Connection context case and cleanup failed',
    )
}

/** Readonly SDK consumers and the page-only public-route exception on real Layouts. */
export function registerLayoutConnectionContextCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect'>,
  mount: (input: LayoutConnectionInput) => Promise<LayoutConnectionView>,
  standalone: () => Promise<LayoutConnectionStandalone>,
): void {
  const { describe, it, expect } = runner
  describe(`HilosLayout connection context on ${sdk}`, () => {
    it('C02 propagates to real page, shell and open modal consumers with readonly fallback and independent shells', async () => {
      const roots: Pick<LayoutConnectionStandalone, 'unmount'>[] = []
      const errors: unknown[] = []
      try {
        const outside = await standalone()
        roots.push(outside)
        expect(outside.interaction.unavailable.get()).toBe(false)
        expect(outside.interaction.blocked.get()).toBe(false)
        expect('set' in outside.interaction.blocked).toBe(false)
        await outside.unmount()

        const feed = layoutChromeFeed()
        const view = await mount({
          connection: feed.connection,
          modalOpen: false,
        })
        roots.push(view)
        await view.update({ modalOpen: true })
        await view.flush(() => feed.state('disconnected'))
        for (const place of ['page', 'shell', 'modal'] as const) {
          expect(view.consumers[place].unavailable.get()).toBe(true)
          expect(view.consumers[place].blocked.get()).toBe(true)
        }
        const independent = await mount({
          connection: layoutChromeFeed().connection,
        })
        roots.push(independent)
        expect(independent.interaction.blocked.get()).toBe(false)
      } catch (error) {
        errors.push(error)
      } finally {
        await release(roots, errors)
      }
    })

    it('C10 separates the public page exception from caller and modal policy through raw route and source changes', async () => {
      const roots: LayoutConnectionView[] = []
      const errors: unknown[] = []
      try {
        const feed = layoutChromeFeed()
        const { router, route } = layoutChromeRouter()
        const view = await mount({
          connection: feed.connection,
          router,
          modalOpen: false,
        })
        roots.push(view)
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
        field.value = 'Retain across policies'
        field.focus()
        await view.flush(() => feed.state('disconnected'))

        await view.flush(() =>
          route.set({
            page: HilosPages.PUBLIC_STATUS,
            params: {},
            admin: false,
          }),
        )
        expect(pageScope.disabled).toBe(false)
        expect(modalScope.disabled).toBe(true)
        expect(document.activeElement).toBe(dialog)

        await view.update({ allow: true })
        expect(modalScope.disabled).toBe(false)
        expect(document.activeElement).toBe(field)
        expect(view.interaction.unavailable.get()).toBe(true)

        await view.update({ allow: false })
        expect(pageScope.disabled).toBe(false)
        expect(modalScope.disabled).toBe(true)
        expect(document.activeElement).toBe(dialog)

        await view.flush(() =>
          route.set({ page: null, params: {}, admin: false }),
        )
        expect(pageScope.disabled).toBe(true)

        const next = layoutChromeFeed()
        await view.update({ connection: next.connection })
        expect(pageScope.disabled).toBe(false)
        expect(modalScope.disabled).toBe(false)
        expect(document.activeElement).toBe(field)
        expect(get(dialog, 'modal-draft')).toBe(field)
        expect(field.value).toBe('Retain across policies')
        expect(feed.listenerCount('state')).toBe(0)
        await view.flush(() => feed.state('reconnecting'))
        expect(pageScope.disabled).toBe(false)
        expect(modalScope.disabled).toBe(false)
        await view.unmount()
        expect(next.listenerCounts().every((count) => count === 0)).toBe(true)
      } catch (error) {
        errors.push(error)
      } finally {
        await release(roots, errors)
      }
    })
  })
}
