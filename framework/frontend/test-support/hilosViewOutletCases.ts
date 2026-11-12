// One text of outlet steps and expectations, mounted by all three real SDKs.
import {
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  type AuthGate,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

import { NOT_FOUND, UNAUTHORIZED } from './errorPageFixtures.js'
import {
  outletNavigation,
  outletRouter,
  type HilosViewOutlet,
  type WithHilosViewOutlet,
} from './hilosViewFixtures.js'

type OutletRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>

/** Register wait, departure, real route miss and the remaining auth frame proof. */
export function registerHilosViewOutletCases(
  sdk: string,
  { describe, it, expect, vi }: OutletRunner,
  mountOutlet: WithHilosViewOutlet,
): void {
  const find = (root: ParentNode, id: string) =>
    root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  const marker = (root: ParentNode) =>
    find(root, 'hilos-page-state')?.getAttribute('data-state')
  const deadline = (view: HilosViewOutlet, ms = DEFAULT_SKELETON_DELAY_MS) =>
    view.flush(() => vi.advanceTimersByTime(ms))
  const withOutlet: WithHilosViewOutlet = async (input, steps) => {
    vi.useFakeTimers()
    try {
      await mountOutlet(input, steps)
    } finally {
      vi.useRealTimers()
    }
  }
  const openGate = (): AuthGate => ({
    modalOpen: createSignal(true),
    requireAuth: () => {},
    dismiss: () => {},
  })

  describe(`HilosView outlet on ${sdk}`, () => {
    it('O01 draws nothing before the skeleton delay', async () => {
      await withOutlet(outletRouter(), async (view) => {
        await deadline(view, DEFAULT_SKELETON_DELAY_MS - 1)
        expect(find(view.root, 'hilos-page-skeleton')).toBeNull()
      })
    })

    it('O02 draws the default skeleton at the deadline', async () => {
      await withOutlet(outletRouter(), async (view) => {
        await deadline(view)
        const skeleton = find(view.root, 'hilos-page-skeleton')
        expect(skeleton).not.toBeNull()
        expect(find(skeleton!, 'hilos-skeleton')).not.toBeNull()
      })
    })

    it('O03 announces loading once beside the empty access region', async () => {
      await withOutlet(outletRouter(), async (view) => {
        await deadline(view)
        const status = view.root.querySelectorAll('[role="status"]')
        expect(status).toHaveLength(2)
        expect(Array.from(status, (region) => region.textContent)).toEqual([
          '',
          'Loading…',
        ])
      })
    })

    it('O04 replaces the shown skeleton with the answered page', async () => {
      const state = outletRouter()
      await withOutlet(state, async (view) => {
        await deadline(view)
        await view.flush(() => state.pageLoading.set(false))
        expect(find(view.root, 'hilos-page-skeleton')).toBeNull()
        expect(find(view.root, 'main')).not.toBeNull()
      })
    })

    it('O05 draws the project skeleton for its declared key', async () => {
      await withOutlet(
        { ...outletRouter(), withSkeletons: true },
        async (view) => {
          await deadline(view)
          expect(find(view.root, 'main-skeleton')).not.toBeNull()
          expect(find(view.root, 'hilos-skeleton')).toBeNull()
        },
      )
    })

    it('O06 uses the default skeleton for an undeclared key', async () => {
      await withOutlet(
        { ...outletRouter('undeclared'), withSkeletons: true },
        async (view) => {
          await deadline(view)
          expect(find(view.root, 'main-skeleton')).toBeNull()
          expect(find(view.root, 'hilos-skeleton')).not.toBeNull()
        },
      )
    })

    it('O07 keeps a refusal ahead of the skeleton', async () => {
      const state = outletRouter()
      await withOutlet(state, async (view) => {
        await view.flush(() =>
          state.pageError.set({ ...NOT_FOUND, page: 'main' }),
        )
        await deadline(view)
        expect(find(view.root, 'hilos-page-skeleton')).toBeNull()
        expect(find(view.root, 'page-error')).not.toBeNull()
      })
    })

    it('O08 publishes loading then ready on the DOM marker', async () => {
      const state = outletRouter()
      await withOutlet(state, async (view) => {
        await deadline(view)
        expect(marker(view.root)).toBe('loading')
        await view.flush(() => state.pageLoading.set(false))
        expect(marker(view.root)).toBe('ready')
      })
    })

    it('O09 holds an inert unaddressable copy until the next answer', async () => {
      const state = outletRouter('main', false)
      await withOutlet(state, async (view) => {
        await view.flush(state.depart)
        const departure = find(view.root, 'hilos-page-departure')!
        expect(departure.getAttribute('aria-hidden')).toBe('true')
        expect(departure.hasAttribute('inert')).toBe(true)
        expect(departure.textContent).toBe('Leaving page')
        expect(departure.querySelector('[data-id]')).toBeNull()
        await view.flush(() => state.pageLoading.set(false))
        expect(departure.childElementCount).toBe(0)
      })
    })

    it('O10 clears the copy on refusal and on a new skeleton deadline', async () => {
      const state = outletRouter('main', false)
      await withOutlet(state, async (view) => {
        const departure = find(view.root, 'hilos-page-departure')!
        await view.flush(state.depart)
        await view.flush(() =>
          state.pageError.set({ ...NOT_FOUND, page: 'main' }),
        )
        expect(departure.childElementCount).toBe(0)
        await view.flush(() => {
          state.pageError.set(null)
          state.pageLoading.set(false)
        })
        await view.flush(state.depart)
        await deadline(view)
        expect(find(view.root, 'hilos-page-skeleton')).not.toBeNull()
        expect(departure.childElementCount).toBe(0)
      })
    })

    it('O11 copies only a departure and retains the first copy on another leave', async () => {
      const state = outletRouter('main', false)
      await withOutlet(state, async (view) => {
        const departure = find(view.root, 'hilos-page-departure')!
        await view.flush(() => state.pageLoading.set(true))
        expect(departure.childElementCount).toBe(0)
        await view.flush(() => state.pageLoading.set(false))
        await view.flush(state.depart)
        const copied = departure.firstElementChild
        expect(copied).not.toBeNull()
        await view.flush(state.depart)
        expect(departure.firstElementChild).toBe(copied)
      })
    })

    it('O12 navigates from a known page through a route miss and back home', async () => {
      const router = outletNavigation()
      try {
        router.start()
        await withOutlet({ router }, async (view) => {
          expect(find(view.root, 'known-page')).not.toBeNull()
          await view.flush(() => router.navigate('/nope'))
          const surface = find(view.root, 'page-error')
          expect(marker(view.root)).toBe('error')
          expect(surface).not.toBeNull()
          expect(view.root.querySelectorAll('h1')).toHaveLength(1)
          expect(surface?.querySelector('h1')?.textContent?.trim()).toBe('404')
          expect(surface?.textContent).toContain('Page Not Found')
          expect(surface?.textContent).toContain(
            'The page you are looking for does not exist.',
          )
          expect(find(view.root, 'known-page')).toBeNull()
          expect(find(view.root, 'main')).toBeNull()
          expect(find(view.root, 'hilos-page-skeleton')).toBeNull()
          await view.flush(() => find(surface!, 'page-error-home')!.click())
          expect(find(view.root, 'main')).not.toBeNull()
          expect(find(view.root, 'page-error')).toBeNull()
          expect(marker(view.root)).toBe('ready')
        })
      } finally {
        router.stop()
      }
    })

    it('O13 draws only the in-place auth surface with an already open gate', async () => {
      const state = outletRouter('main', false)
      state.pageError.set({ ...UNAUTHORIZED, page: 'main' })
      await withOutlet(
        { ...state, authSurface: {}, authGate: openGate() },
        async () => {
          expect(
            document.querySelectorAll('[data-id="auth-surface"]'),
          ).toHaveLength(1)
          expect(document.querySelector('[data-id="modal"]')).toBeNull()
        },
      )
    })

    for (const heading of ['Confirm your email', undefined]) {
      it(`O14 adds no frame heading to a ${heading === undefined ? 'headingless' : 'headed'} auth surface`, async () => {
        await withOutlet(
          {
            ...outletRouter('main', false),
            authSurface: { heading },
            authGate: openGate(),
          },
          async () => {
            const dialog = document.querySelector('[data-id="modal"]')
            expect(dialog).not.toBeNull()
            const headings = dialog!.querySelectorAll(
              'h1,h2,h3,h4,h5,h6,[role="heading"]',
            )
            expect(headings).toHaveLength(heading === undefined ? 0 : 1)
            for (const node of headings) {
              expect(
                dialog!
                  .querySelector('[data-id="auth-surface"]')
                  ?.contains(node),
              ).toBe(true)
            }
          },
        )
      })
    }
  })
}
