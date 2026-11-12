import { HilosPages, hilosToasts } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  layoutChromeConnection,
  layoutChromeRouter,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutReconsentHandshake,
  layoutReconsentSession,
} from './layoutReconsentFixtures.js'

type ReconsentFrozenRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** The account freeze replaces content except on the declared personal exits. */
export function registerLayoutReconsentFrozenCases(
  sdk: string,
  runner: ReconsentFrozenRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner

  async function withLayout(
    input: Pick<LayoutChromeInput, 'router'>,
    steps: (
      view: LayoutChromeView,
      session: Awaited<ReturnType<typeof layoutReconsentSession>>,
    ) => void | Promise<void>,
  ): Promise<void> {
    let session: Awaited<ReturnType<typeof layoutReconsentSession>> | undefined
    let view: LayoutChromeView | undefined
    try {
      session = await layoutReconsentSession()
      session.handshake(
        layoutReconsentHandshake({
          lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
          frozen: true,
        }),
      )
      view = await mount({ connection: layoutChromeConnection(), ...input })
      await view.flush()
      await steps(view, session)
    } finally {
      try {
        await view?.unmount()
      } finally {
        try {
          if (session) {
            if (runner.releaseBindings)
              await runner.releaseBindings(session.release)
            else await session.release()
          }
        } finally {
          hilosToasts.clear()
        }
      }
    }
  }

  describe(`HilosLayout legal freeze on ${sdk}`, () => {
    it('UR10 puts the freeze screen in place of the content with no icon', async () => {
      await withLayout({}, ({ root }, session) => {
        const screen = part(root, 'legal-frozen-screen')
        expect(screen).not.toBeNull()
        expect(
          screen
            ?.querySelector('[data-id="legal-reconsent"]')
            ?.getAttribute('data-variant'),
        ).toBe('frozen')
        expect(part(root, 'page-body')).toBeNull()
        expect(part(root, 'app-footer')).not.toBeNull()
        expect(part(root, 'legal-reconsent-icon')).toBeNull()
        expect(session.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
      })
    })

    it('UR11 leaves the pages the freeze keeps open to their content', async () => {
      const { router, route } = layoutChromeRouter()
      route.set({ page: HilosPages.PROFILE_DATA, params: {}, admin: false })
      await withLayout({ router }, ({ root }) => {
        expect(part(root, 'legal-frozen-screen')).toBeNull()
        expect(part(root, 'page-body')).not.toBeNull()
      })
    })

    it('UR12 covers an address with no page with the freeze screen', async () => {
      const { router } = layoutChromeRouter()
      await withLayout({ router }, ({ root }) => {
        expect(part(root, 'legal-frozen-screen')).not.toBeNull()
        expect(part(root, 'page-body')).toBeNull()
      })
    })
  })
}
