import type * as Vitest from 'vitest'
import {
  layoutAdminGreeting,
  layoutAdminSession,
} from './layoutAdminFixtures.js'
import {
  FROZEN,
  layoutChromeConnection,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'

type LayoutAdminGearRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
> & {
  afterEach(release: () => void): void
}

/** One semantic gear contract, rendered through each SDK's native Layout. */
export function registerLayoutAdminGearCases(
  sdk: string,
  runner: LayoutAdminGearRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, afterEach } = runner

  function gear(root: ParentNode): HTMLElement {
    const link = root.querySelector<HTMLElement>('[data-id="nav-admin"]')
    expect(link).not.toBeNull()
    return link!
  }

  describe(`HilosLayout admin gear on ${sdk}`, () => {
    let release: (() => void) | undefined
    afterEach(() => {
      try {
        release?.()
      } finally {
        release = undefined
      }
    })

    async function withGear(
      greeting: Record<string, unknown>,
      steps: (
        view: LayoutChromeView,
        session: ReturnType<typeof layoutAdminSession>,
      ) => void | Promise<void>,
      connection = layoutChromeConnection(),
    ): Promise<void> {
      const session = layoutAdminSession()
      release = session.release
      let view: LayoutChromeView | undefined
      try {
        session.handshake(greeting)
        view = await mount({ connection })
        await steps(view, session)
      } finally {
        // The scoped native hook releases observable bindings after disposal.
        await view?.unmount()
      }
    }

    it('G01 draws no gear for a guest without view mode', async () => {
      await withGear(layoutAdminGreeting(null, false), (view) => {
        expect(view.root.querySelector('[data-id="nav-admin"]')).toBeNull()
      })
    })

    it('G02 draws no gear for a signed-in non-admin without view mode', async () => {
      await withGear(
        layoutAdminGreeting({ id: 7, admin: false }, false),
        (view) => {
          expect(view.root.querySelector('[data-id="nav-admin"]')).toBeNull()
        },
      )
    })

    it('G03 draws the full gear for an admin, leading to the dashboard', async () => {
      await withGear(
        layoutAdminGreeting({ id: 7, admin: true }, false),
        (view) => {
          const link = gear(view.root)
          expect(link.getAttribute('data-access')).toBe('full')
          expect(link.getAttribute('href')).toBe('/hilos')
          expect(link.getAttribute('aria-label')).toBe('Hilos dashboard')
          expect(link.getAttribute('title')).toBeNull()
          expect(link.querySelector('.visually-hidden')?.textContent).toBe(
            'Hilos dashboard',
          )
          expect(
            link.querySelector('.bi-gear')?.getAttribute('aria-hidden'),
          ).toBe('true')
          expect(link.querySelectorAll('.bi-eye')).toHaveLength(0)
        },
      )
    })

    it.each([
      ['G04a', 'a guest', null],
      ['G04b', 'a signed-in non-admin', { id: 7, admin: false }],
    ] as const)('%s draws the view gear for %s', async (_id, _who, user) => {
      await withGear(layoutAdminGreeting(user, true), (view) => {
        const link = gear(view.root)
        expect(link.getAttribute('data-access')).toBe('view')
        expect(link.getAttribute('href')).toBe('/hilos')
        expect(link.getAttribute('aria-label')).toBe(
          'Hilos dashboard — View mode',
        )
        expect(link.getAttribute('title')).toBe('Hilos dashboard — View mode')
        expect(link.querySelector('.visually-hidden')?.textContent).toBe(
          'Hilos dashboard — View mode',
        )
        expect(
          link.querySelector('.bi-gear')?.getAttribute('aria-hidden'),
        ).toBe('true')
        expect(link.querySelectorAll('.bi-eye')).toHaveLength(1)
        expect(link.querySelector('.bi-eye')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
      })
    })

    it('G05 turns full on a grant and back to view on a revoke, live', async () => {
      await withGear(
        layoutAdminGreeting({ id: 7, admin: false }, true),
        async (view, session) => {
          await view.flush(() =>
            session.handshake(
              layoutAdminGreeting({ id: 7, admin: true }, true),
            ),
          )
          expect(
            view.root.querySelectorAll('[data-id="nav-admin"]'),
          ).toHaveLength(1)
          const fullLink = gear(view.root)
          expect(fullLink.getAttribute('data-access')).toBe('full')
          expect(fullLink.getAttribute('aria-label')).toBe('Hilos dashboard')
          expect(fullLink.getAttribute('title')).toBeNull()
          expect(fullLink.querySelector('.visually-hidden')?.textContent).toBe(
            'Hilos dashboard',
          )
          expect(fullLink.querySelectorAll('.bi-eye')).toHaveLength(0)

          await view.flush(() =>
            session.handshake(
              layoutAdminGreeting({ id: 7, admin: false }, true),
            ),
          )
          expect(
            view.root.querySelectorAll('[data-id="nav-admin"]'),
          ).toHaveLength(1)
          const viewLink = gear(view.root)
          expect(viewLink.getAttribute('data-access')).toBe('view')
          expect(viewLink.getAttribute('aria-label')).toBe(
            'Hilos dashboard — View mode',
          )
          expect(viewLink.getAttribute('title')).toBe(
            'Hilos dashboard — View mode',
          )
          expect(viewLink.querySelector('.visually-hidden')?.textContent).toBe(
            'Hilos dashboard — View mode',
          )
          expect(viewLink.querySelectorAll('.bi-eye')).toHaveLength(1)
          expect(
            viewLink.querySelector('.bi-eye')?.getAttribute('aria-hidden'),
          ).toBe('true')
        },
      )
    })

    it('G06 draws no gear under the maintenance surface', async () => {
      await withGear(
        layoutAdminGreeting(null, true),
        (view) => {
          expect(view.root.querySelector('[data-id="nav-admin"]')).toBeNull()
        },
        layoutChromeConnection(FROZEN),
      )
    })
  })
}
