import {
  applyServerTime,
  formatCalendarDate,
  hilosToasts,
} from '@hilos/core/base'
import { getByRole } from '@testing-library/dom'
import type * as Vitest from 'vitest'
import {
  layoutChromeConnection,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutStandingGreeting,
  layoutStandingSession,
  STANDING_NOW,
  withLayoutStandingClock,
} from './layoutStandingFixtures.js'

type StandingMomentRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}

const DAY_MS = 86_400_000

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** The same deletion dates, countdown and grace cap on each real SDK Layout. */
export function registerLayoutStandingMomentCases(
  sdk: string,
  runner: StandingMomentRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, vi } = runner

  async function withLayout(
    greeting: Record<string, unknown>,
    steps: (view: LayoutChromeView) => void | Promise<void>,
    beforeMount?: () => void,
  ): Promise<void> {
    await withLayoutStandingClock(vi, async () => {
      let session: Awaited<ReturnType<typeof layoutStandingSession>> | undefined
      let view: LayoutChromeView | undefined
      try {
        session = await layoutStandingSession()
        session.handshake(greeting)
        beforeMount?.()
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
            hilosToasts.clear()
          }
        }
      }
    })
  }

  describe(`HilosLayout standing moments on ${sdk}`, () => {
    it('AS01 says when the own account is deleted and how many days are left', async () => {
      const effectiveAt = STANDING_NOW + 12 * DAY_MS
      await withLayout(
        layoutStandingGreeting('deletion_scheduled', false, effectiveAt),
        ({ root }) => {
          const strip = part(root, 'account-deletion-strip')
          expect(strip).not.toBeNull()
          expect(
            part(strip!, 'account-deletion-strip-text')?.textContent?.trim(),
          ).toBe(
            `Your account will be deleted on ${formatCalendarDate(effectiveAt)} — 12 days left`,
          )
          expect(getByRole(strip!, 'button', { name: 'Keep my account' })).toBe(
            part(strip!, 'account-deletion-strip-keep'),
          )
        },
      )
    })

    it('AS02 counts the days left again once a minute', async () => {
      await withLayout(
        layoutStandingGreeting(
          'deletion_scheduled',
          false,
          STANDING_NOW + 2 * DAY_MS,
        ),
        async (view) => {
          const text = () =>
            part(view.root, 'account-deletion-strip-text')?.textContent
          expect(text()).toContain('2 days left')

          await view.flush(() => {
            vi.setSystemTime(STANDING_NOW + 1.5 * DAY_MS)
            vi.advanceTimersByTime(60_000)
          })

          expect(text()).toContain('1 day left')
        },
      )
    })

    it('AS03 bounds the strip by the standing grace period with a negative clock error and keeps ticking', async () => {
      const effectiveAt = STANDING_NOW + 30 * DAY_MS
      await withLayout(
        layoutStandingGreeting('deletion_scheduled', false, effectiveAt, 30),
        async (view) => {
          const text = () =>
            part(view.root, 'account-deletion-strip-text')?.textContent?.trim()
          expect(text()).toBe(
            `Your account will be deleted on ${formatCalendarDate(effectiveAt)} — 30 days left`,
          )

          await view.flush(() => {
            vi.setSystemTime(STANDING_NOW + DAY_MS)
            vi.advanceTimersByTime(60_000)
          })

          expect(text()).toBe(
            `Your account will be deleted on ${formatCalendarDate(effectiveAt)} — 29 days left`,
          )
        },
        () => applyServerTime(STANDING_NOW - 300),
      )
    })
  })
}
