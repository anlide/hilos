import { formatCalendarDate } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  layoutChromeConnection,
  layoutChromeRouter,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutStandingGreeting,
  layoutStandingSession,
  STANDING_NOW,
  withLayoutStandingClock,
} from './layoutStandingFixtures.js'

type LayoutStandingChromeRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}

type Session = Awaited<ReturnType<typeof layoutStandingSession>>

const DAY_MS = 86_400_000

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Run one standing timeline against a real Layout and its native lifecycle. */
export function registerLayoutStandingChromeCases(
  sdk: string,
  runner: LayoutStandingChromeRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, vi } = runner

  async function withStandingChrome(
    steps: (view: LayoutChromeView, session: Session) => Promise<void>,
  ): Promise<void> {
    await withLayoutStandingClock(vi, async () => {
      let session: Session | undefined
      let view: LayoutChromeView | undefined
      const errors: unknown[] = []

      try {
        session = await layoutStandingSession()
        session.handshake(
          layoutStandingGreeting(
            'deletion_scheduled',
            false,
            STANDING_NOW + 3 * DAY_MS,
            null,
          ),
        )
        const { router } = layoutChromeRouter()
        view = await mount({ connection: layoutChromeConnection(), router })
        await steps(view, session)
      } catch (error) {
        errors.push(error)
      }

      try {
        await view?.unmount()
      } catch (error) {
        errors.push(error)
      }

      try {
        if (session) {
          if (runner.releaseBindings)
            await runner.releaseBindings(session.release)
          else await session.release()
        }
      } catch (error) {
        errors.push(error)
      }

      if (errors.length === 1) throw errors[0]
      if (errors.length > 1)
        throw new AggregateError(errors, 'Standing chrome cleanup failed')
    })
  }

  describe(`HilosLayout live standing chrome on ${sdk}`, () => {
    it('follows the current person live and preserves blocked/frozen priority over deletion', async () => {
      await withStandingChrome(async (view, session) => {
        expect(part(view.root, 'account-deletion-strip-keep')).not.toBeNull()

        await view.flush(() =>
          session.handshake({
            ...layoutStandingGreeting(
              'deletion_scheduled',
              true,
              STANDING_NOW + 5 * DAY_MS,
              null,
            ),
            entities: {
              currentUser: { id: 3, name: 'Eve' },
              impersonatedBy: { id: 1, name: 'Ada' },
            },
          }),
        )
        expect(
          part(view.root, 'impersonation-banner')?.querySelector('strong')
            ?.textContent,
        ).toBe('Eve')
        expect(
          part(view.root, 'account-deletion-strip-text')?.textContent,
        ).toContain(
          `Your account will be deleted on ${formatCalendarDate(STANDING_NOW + 5 * DAY_MS)} — 5 days left`,
        )
        expect(part(view.root, 'account-deletion-strip-keep')).toBeNull()

        for (const standing of ['blocked', 'frozen'] as const) {
          await view.flush(() =>
            session.handshake(
              layoutStandingGreeting(
                standing,
                true,
                STANDING_NOW + 5 * DAY_MS,
                null,
              ),
            ),
          )
          expect(part(view.root, 'account-deletion-strip')).toBeNull()
          expect(part(view.root, 'impersonation-banner')).not.toBeNull()
        }

        await view.flush(() =>
          session.handshake({
            ...layoutStandingGreeting(
              'deletion_scheduled',
              false,
              STANDING_NOW + 7 * DAY_MS,
              null,
            ),
            entities: {
              currentUser: { id: 4, name: 'Carol' },
              impersonatedBy: null,
            },
          }),
        )
        expect(part(view.root, 'impersonation-banner')).toBeNull()
        expect(part(view.root, 'account-deletion-strip-keep')).not.toBeNull()
        expect(
          part(view.root, 'account-deletion-strip-text')?.textContent,
        ).toContain(
          `Your account will be deleted on ${formatCalendarDate(STANDING_NOW + 7 * DAY_MS)} — 7 days left`,
        )
      })
    })
  })
}
