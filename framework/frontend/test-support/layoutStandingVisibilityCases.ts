import {
  applyServerTime,
  formatCalendarDate,
  hilosToasts,
  offsetMs,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  ADMITTED,
  FROZEN,
  layoutChromeConnection,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutStandingGreeting,
  layoutStandingSession,
  withLayoutStandingClock,
} from './layoutStandingFixtures.js'

type StandingVisibilityRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}

type ProjectBanner = NonNullable<LayoutChromeInput['banner']>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector('[data-id="' + id + '"]')
}

/** The same standing visibility and banner composition on each real SDK Layout. */
export function registerLayoutStandingVisibilityCases(
  sdk: string,
  runner: StandingVisibilityRunner,
  mount: MountLayoutChrome,
  projectBanner: ProjectBanner,
): void {
  const { describe, it, expect, vi } = runner

  async function withLayout(
    greeting: Record<string, unknown>,
    connection: LayoutChromeInput['connection'],
    banner: LayoutChromeInput['banner'] | undefined,
    steps: (view: LayoutChromeView) => void | Promise<void>,
  ): Promise<void> {
    const entryOffset = offsetMs()
    let session: Awaited<ReturnType<typeof layoutStandingSession>> | undefined
    let view: LayoutChromeView | undefined
    try {
      session = await layoutStandingSession()
      session.handshake(greeting)
      view = await mount({
        connection,
        ...(banner === undefined ? {} : { banner }),
      })
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

  describe('HilosLayout standing visibility on ' + sdk, () => {
    it('shows each framework strip and the project banner together', async () => {
      const effectiveAt = 1_800_259_200_000
      await withLayout(
        layoutStandingGreeting('deletion_scheduled', false, effectiveAt, null),
        layoutChromeConnection(ADMITTED),
        projectBanner,
        ({ root }) => {
          expect(root.querySelectorAll('[data-id="app-banner"]')).toHaveLength(
            1,
          )
          const region = root.querySelector('[data-id="app-banner"]')
          for (const id of [
            'protected-mode-banner',
            'account-deletion-strip',
            projectBanner.dataId,
          ]) {
            expect(
              region?.querySelectorAll('[data-id="' + id + '"]'),
            ).toHaveLength(1)
          }
        },
      )
    })

    it('shows the scheduled deletion details during a takeover without a Keep action', async () => {
      const effectiveAt = 1_800_259_200_000
      await withLayoutStandingClock(vi, async () => {
        await withLayout(
          layoutStandingGreeting('deletion_scheduled', true, effectiveAt, null),
          layoutChromeConnection(),
          undefined,
          ({ root }) => {
            expect(part(root, 'impersonation-banner')).not.toBeNull()
            expect(part(root, 'account-deletion-strip')).not.toBeNull()
            expect(part(root, 'account-deletion-strip-keep')).toBeNull()
            expect(
              part(root, 'account-deletion-strip-text')?.textContent?.trim(),
            ).toBe(
              'Your account will be deleted on ' +
                formatCalendarDate(effectiveAt) +
                ' — 3 days left',
            )
          },
        )
      })
    })

    it('hides the deletion strip during node maintenance', async () => {
      const effectiveAt = 1_800_259_200_000
      await withLayout(
        layoutStandingGreeting('deletion_scheduled', false, effectiveAt, null),
        layoutChromeConnection(FROZEN),
        undefined,
        ({ root }) => {
          expect(part(root, 'account-deletion-strip')).toBeNull()
        },
      )
    })
  })
}
