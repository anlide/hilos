import type * as Vitest from 'vitest'
import { HILOS_VIEW_MODE_STRIP_TEXT_ID, HilosPages } from '@hilos/core/base'
import {
  layoutViewerGreeting,
  layoutViewModeSession,
  VIEW_MODE_NOW,
} from './layoutAdminFixtures.js'
import {
  ADMITTED,
  FROZEN,
  layoutChromeConnection,
  layoutChromeRouter,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'

type LayoutViewModeRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
> & {
  afterEach(release: () => void): void
}

/** One viewer-strip contract exercised through the three native Layouts. */
export function registerLayoutViewModeCases(
  sdk: string,
  runner: LayoutViewModeRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, afterEach } = runner
  function strip(root: ParentNode): HTMLElement | null {
    return root.querySelector('[data-id="view-mode-banner"]')
  }

  describe(`HilosLayout view-mode strip on ${sdk}`, () => {
    let release: (() => void) | undefined
    afterEach(() => {
      try {
        release?.()
      } finally {
        release = undefined
      }
    })

    async function withViewer(
      greeting: Record<string, unknown>,
      steps: (
        view: LayoutChromeView,
        session: ReturnType<typeof layoutViewModeSession>,
      ) => void | Promise<void>,
      adminRoute = true,
      input: LayoutChromeInput = { connection: layoutChromeConnection() },
    ): Promise<void> {
      const session = layoutViewModeSession()
      release = session.release
      let view: LayoutChromeView | undefined
      try {
        session.handshake(greeting)
        const { router, route } = layoutChromeRouter()
        route.set({
          page: adminRoute ? HilosPages.DASHBOARD : HilosPages.PROFILE_DATA,
          params: {},
          admin: adminRoute,
        })
        view = await mount({ ...input, router })
        await steps(view, session)
      } finally {
        // The scoped native hook releases bindings after the Layout is gone.
        await view?.unmount()
      }
    }

    it('W01 tells a viewer the admin screen may be looked at and not changed', async () => {
      await withViewer(layoutViewerGreeting(false, true), (view) => {
        const banner = strip(view.root)
        expect(banner).not.toBeNull()
        expect(
          banner?.querySelector('.bi-eye')?.getAttribute('aria-hidden'),
        ).toBe('true')
        const text = banner?.querySelector(`#${HILOS_VIEW_MODE_STRIP_TEXT_ID}`)
        expect(text).not.toBeNull()
        expect(text?.querySelector('strong')?.textContent?.trim()).toBe(
          'View mode',
        )
        expect(text?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
          'View mode · You can look around, but not change anything.',
        )
      })
    })

    it('W02 stands after the session strips and before the project strip', async () => {
      await withViewer(
        layoutViewerGreeting(false, true, VIEW_MODE_NOW + 3 * 86_400_000),
        (view) => {
          const region = view.root.querySelector('[data-id="app-banner"]')
          const order = Array.from(region?.children ?? []).map((child) =>
            child.getAttribute('data-id'),
          )
          expect(order).toEqual([
            'protected-mode-banner',
            'account-deletion-strip',
            'view-mode-banner',
            'test-banner',
          ])
        },
        true,
        {
          connection: layoutChromeConnection(ADMITTED),
          banner: { dataId: 'test-banner', text: 'A trial notice' },
        },
      )
    })

    it.each([
      ['W03a', 'on a route outside the admin section', false, true, false],
      ['W03b', 'for an admin', true, true, true],
      ['W03c', 'on a node without the view mode', false, false, true],
    ] as const)(
      '%s draws no strip %s',
      async (_id, _case, admin, viewMode, adminRoute) => {
        await withViewer(
          layoutViewerGreeting(admin, viewMode),
          (view) => {
            expect(strip(view.root)).toBeNull()
          },
          adminRoute,
        )
      },
    )

    it('W04 draws no strip under the maintenance surface', async () => {
      await withViewer(
        layoutViewerGreeting(false, true),
        (view) => {
          expect(strip(view.root)).toBeNull()
        },
        true,
        { connection: layoutChromeConnection(FROZEN) },
      )
    })

    it('W05 leaves on a grant and comes back on a revoke, live', async () => {
      await withViewer(
        layoutViewerGreeting(false, true),
        async (view, session) => {
          expect(strip(view.root)).not.toBeNull()
          await view.flush(() =>
            session.handshake(layoutViewerGreeting(true, true)),
          )
          expect(strip(view.root)).toBeNull()
          await view.flush(() =>
            session.handshake(layoutViewerGreeting(false, true)),
          )
          expect(strip(view.root)).not.toBeNull()
        },
      )
    })
  })
}
