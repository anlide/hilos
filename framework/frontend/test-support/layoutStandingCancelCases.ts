import {
  applyServerTime,
  HILOS_ACCOUNT_DELETION_CANCEL_ACTION,
  hilosToasts,
  offsetMs,
} from '@hilos/core/base'
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

type StandingCancelRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}
type Session = Awaited<ReturnType<typeof layoutStandingSession>>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Shared tracked Keep outcomes on each real SDK Layout, using the local clock. */
export function registerLayoutStandingCancelCases(
  sdk: string,
  runner: StandingCancelRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner

  async function withLayout(
    steps: (view: LayoutChromeView, session: Session) => Promise<void>,
  ): Promise<void> {
    const entryOffset = offsetMs()
    let session: Session | undefined
    let view: LayoutChromeView | undefined
    try {
      session = await layoutStandingSession()
      session.handshake(
        layoutStandingGreeting(
          'deletion_scheduled',
          false,
          1_800_259_200_000,
          null,
        ),
      )
      view = await mount({ connection: layoutChromeConnection() })
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
          try {
            hilosToasts.clear()
          } finally {
            applyServerTime(Date.now() + entryOffset)
          }
        }
      }
    }
  }

  describe(`HilosLayout standing cancellation on ${sdk}`, () => {
    it('AS07 sends Keep my account tracked, busy until the reply, and toasts nothing on success', async () => {
      await withLayout(async (view, session) => {
        const keep = part(
          view.root,
          'account-deletion-strip-keep',
        ) as HTMLButtonElement
        await view.flush(() => keep.click())

        expect(session.sent).toHaveLength(1)
        expect(session.sent[0]?.action).toBe('hilos_account_deletion_cancel')
        expect(session.sent[0]?.data).toEqual({})
        expect(session.sent[0]?.requestId).toBeTruthy()
        expect(keep.disabled).toBe(false)
        expect(keep.getAttribute('aria-disabled')).toBe('true')
        expect(keep.getAttribute('aria-busy')).toBe('true')

        await view.flush(() => keep.click())
        expect(session.sent).toHaveLength(1)

        await view.flush(() => {
          session.succeed(
            session.sent[0]?.requestId,
            HILOS_ACCOUNT_DELETION_CANCEL_ACTION,
          )
          session.handshake(layoutStandingGreeting('none', false, null, null))
        })

        expect(part(view.root, 'account-deletion-strip')).toBeNull()
        expect(hilosToasts.toasts.get()).toEqual([])
      })
    })

    it('AS08 leaves the strip standing on a refusal and says why in a toast', async () => {
      await withLayout(async (view, session) => {
        const keep = part(
          view.root,
          'account-deletion-strip-keep',
        ) as HTMLButtonElement
        await view.flush(() => keep.click())
        await view.flush(() =>
          session.refuse(
            session.sent[0]?.requestId,
            'No deletion is scheduled',
            HILOS_ACCOUNT_DELETION_CANCEL_ACTION,
          ),
        )

        expect(part(view.root, 'account-deletion-strip')).not.toBeNull()
        expect(keep.disabled).toBe(false)
        expect(keep.getAttribute('aria-disabled')).toBeNull()
        expect(keep.getAttribute('aria-busy')).toBeNull()
        expect(
          hilosToasts.toasts
            .get()
            .map((toast) => [toast.severity, toast.message]),
        ).toEqual([['error', 'No deletion is scheduled']])
      })
    })
  })
}
