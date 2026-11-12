import {
  HILOS_INTERACTION_REGION_ATTR,
  PROTECTED_MODE_INACTIVE,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  ADMITTED,
  FROZEN,
  layoutChromeFeed,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import { MINTED, VERIFYING } from './maintenanceFixtures.js'
import { withLayoutStandingClock } from './layoutStandingFixtures.js'

type LayoutProtectedResidueRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

function get(root: ParentNode, id: string): HTMLElement {
  const element = part(root, id)
  if (!element) throw new Error(`Missing Layout part: ${id}`)
  return element
}

/** Preserve cold constructors and live deliveries through the real shell. */
export function registerLayoutProtectedResidueCases(
  sdk: string,
  runner: LayoutProtectedResidueRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, vi } = runner

  async function withChrome(
    feed: ReturnType<typeof layoutChromeFeed>,
    steps: (view: LayoutChromeView) => Promise<void> | void,
  ): Promise<void> {
    await withLayoutStandingClock(vi, async () => {
      let view: LayoutChromeView | undefined
      const errors: unknown[] = []
      try {
        view = await mount({ connection: feed.connection }, (partial) => {
          view = partial
        })
        await steps(view)
      } catch (error) {
        errors.push(error)
      } finally {
        try {
          await view?.unmount()
        } catch (error) {
          errors.push(error)
        }
      }
      if (errors.length === 1) throw errors[0]
      if (errors.length > 1)
        throw new AggregateError(errors, 'Protected Layout cleanup failed')
    })
  }

  describe(`HilosLayout protected-mode residue on ${sdk}`, () => {
    it('LP01 preserves cold shell branches and live freeze and banner removal', async () => {
      await withChrome(layoutChromeFeed(), ({ root }) => {
        expect(part(root, 'page-body')).not.toBeNull()
        expect(part(root, 'maintenance')).toBeNull()
        expect(part(root, 'nav-brand')).not.toBeNull()
        expect(part(root, 'app-footer')).not.toBeNull()
      })

      await withChrome(layoutChromeFeed(FROZEN), ({ root }) => {
        expect(part(root, 'maintenance')).not.toBeNull()
        expect(part(root, 'page-body')).toBeNull()
        expect(part(root, 'nav-brand')).toBeNull()
        expect(part(root, 'nav-admin')).toBeNull()
        expect(part(root, 'app-footer')).toBeNull()
        expect(part(root, 'conn-state')).not.toBeNull()
      })

      await withChrome(layoutChromeFeed(VERIFYING), ({ root }) => {
        expect(part(root, 'protected-mode-banner')).toBeNull()
      })

      const admitted = layoutChromeFeed(ADMITTED)
      await withChrome(admitted, async (view) => {
        expect(part(view.root, 'page-body')).not.toBeNull()
        expect(part(view.root, 'maintenance')).toBeNull()
        expect(get(view.root, 'protected-mode-banner').textContent).toContain(
          'The restore is being verified.',
        )
        await view.flush(() => admitted.protectedMode(PROTECTED_MODE_INACTIVE))
        expect(part(view.root, 'protected-mode-banner')).toBeNull()
      })

      const live = layoutChromeFeed()
      await withChrome(live, async (view) => {
        await view.flush(() => live.protectedMode(FROZEN))
        expect(get(view.root, 'maintenance-title').textContent).toBe(
          'Restoring a backup',
        )
        expect(part(view.root, 'page-body')).toBeNull()
      })
    })

    it('LC01 names first connect, dragging reconnect and recovered connection', async () => {
      const connecting = layoutChromeFeed()
      connecting.state('connecting')
      await withChrome(connecting, ({ root }) => {
        expect(get(root, 'conn-state').getAttribute('title')).toBe('connecting')
      })

      const reconnecting = layoutChromeFeed()
      await withChrome(reconnecting, async (view) => {
        await view.flush(() => reconnecting.state('reconnecting'))
        expect(get(view.root, 'conn-state').getAttribute('title')).toBe(
          'reconnecting',
        )
        await view.flush(() => {
          reconnecting.reconnectDragging(true)
          reconnecting.state('reconnecting')
        })
        expect(get(view.root, 'conn-state').getAttribute('title')).toContain(
          'taking longer than usual',
        )
      })

      const recovering = layoutChromeFeed()
      await withChrome(recovering, async (view) => {
        await view.flush(() => {
          recovering.state('reconnecting')
          recovering.reconnectDragging(true)
        })
        await view.flush(() => {
          recovering.state('connected')
          recovering.reconnectDragging(false)
        })
        expect(get(view.root, 'conn-state').getAttribute('title')).toBe(
          'connected',
        )
      })
    })

    it('LS01 keeps the native Service status exit outside the disconnected page gate', async () => {
      const feed = layoutChromeFeed(MINTED)
      feed.state('disconnected')
      await withChrome(feed, ({ root }) => {
        const link = get(root, 'maintenance-service-status')
        expect(link.getAttribute('href')).toBe('/status')
        expect(link.closest('fieldset, [inert]')).toBeNull()
        expect(
          root.querySelector(`[${HILOS_INTERACTION_REGION_ATTR}]`),
        ).toBeNull()

        let prevented: boolean | undefined
        const preventNavigation = (event: Event): void => {
          prevented = event.defaultPrevented
          event.preventDefault()
        }
        try {
          link.addEventListener('click', preventNavigation, { once: true })
          link.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
          )
          expect(prevented).toBe(false)
        } finally {
          link.removeEventListener('click', preventNavigation)
        }
        expect(feed.presented).toEqual([])
      })
    })
  })
}
