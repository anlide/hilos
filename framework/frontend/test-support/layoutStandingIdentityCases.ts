import { applyServerTime, hilosToasts, offsetMs } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  layoutChromeConnection,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutStandingGreeting,
  layoutStandingSession,
} from './layoutStandingFixtures.js'

type StandingIdentityRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}

type Session = Awaited<ReturnType<typeof layoutStandingSession>>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Preserve the represented identity for each raw standing on every real SDK. */
export function registerLayoutStandingIdentityCases(
  sdk: string,
  runner: StandingIdentityRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner

  async function withLayout(
    shown: string,
    steps: (view: LayoutChromeView) => void | Promise<void>,
  ): Promise<void> {
    const entryOffset = offsetMs()
    let session: Session | undefined
    let view: LayoutChromeView | undefined
    try {
      session = await layoutStandingSession()
      session.handshake(layoutStandingGreeting(shown, true, null, null))
      view = await mount({ connection: layoutChromeConnection() })
      await steps(view)
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
          try {
            hilosToasts.clear()
          } finally {
            applyServerTime(Date.now() + entryOffset)
          }
        }
      }
    }
  }

  describe(`HilosLayout standing identity on ${sdk}`, () => {
    it.each([
      ['AS09a', 'none'],
      ['AS09b', 'blocked'],
      ['AS09c', 'frozen'],
    ])(
      '%s preserves Bob in the impersonation strip for %s standing',
      async (_caseId, shown) => {
        await withLayout(shown, ({ root }) => {
          const strip = part(root, 'impersonation-banner')
          expect(strip).not.toBeNull()
          expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
        })
      },
    )
  })
}
