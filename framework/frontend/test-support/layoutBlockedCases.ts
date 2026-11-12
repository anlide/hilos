import { hilosToasts } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  BLOCKED_GREETING,
  layoutBlockedSession,
} from './layoutBlockedFixtures.js'
import {
  FROZEN,
  layoutChromeConnection,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'

type BlockedRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}
type Session = Awaited<ReturnType<typeof layoutBlockedSession>>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Native templates may insert whitespace between the card's text nodes. */
function text(root: ParentNode, id: string): string | undefined {
  return part(root, id)?.textContent?.replace(/\s+/g, ' ').trim()
}

/** Cold card behavior, read from real session bindings by each native Layout. */
export function registerLayoutBlockedCases(
  sdk: string,
  runner: BlockedRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner

  async function withLayout(
    greeting: Record<string, unknown>,
    steps: (view: LayoutChromeView, session: Session) => void | Promise<void>,
    input: LayoutChromeInput = { connection: layoutChromeConnection() },
  ): Promise<void> {
    let session: Session | undefined
    let view: LayoutChromeView | undefined
    try {
      session = await layoutBlockedSession()
      session.handshake(greeting)
      view = await mount(input)
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

  describe(`HilosLayout cold blocked card on ${sdk}`, () => {
    it('BC01 replaces the page with the named card and keeps chrome without a live-loss notice', async () => {
      await withLayout(BLOCKED_GREETING, ({ root }) => {
        expect(part(root, 'account-blocked')).not.toBeNull()
        expect(part(root, 'data-export-prepare')).not.toBeNull()
        expect(part(root, 'page-body')).toBeNull()
        expect(part(root, 'app-footer')).not.toBeNull()
        expect(root.querySelector('nav')).not.toBeNull()
        expect(text(root, 'account-blocked-heading')).toBe('Access closed')
        expect(text(root, 'account-blocked-account')).toBe(
          'The account maria@example.com has been blocked by the project administration.',
        )
        expect(text(root, 'account-blocked-reason')).toContain(
          'No reason given',
        )
        expect(part(root, 'access-loss-notice')).toBeNull()
      })
    })

    it('BC02 says This account when the server could name no address', async () => {
      await withLayout(
        { data: { accountBlocked: { identifier: null } } },
        ({ root }) => {
          expect(text(root, 'account-blocked-account')).toBe(
            'This account has been blocked by the project administration.',
          )
        },
      )
    })

    it('BC03 guards a repeated dismiss until its tracked success settles', async () => {
      await withLayout(BLOCKED_GREETING, async (view, session) => {
        const signOut = part(
          view.root,
          'account-blocked-sign-out',
        ) as HTMLButtonElement
        await view.flush(() => signOut.click())
        expect(session.sent).toHaveLength(1)
        expect(session.sent[0]?.action).toBe('hilos_dismiss_account_blocked')
        expect(session.sent[0]?.data).toEqual({})
        const requestId = session.sent[0]?.requestId
        expect(requestId).toBeTruthy()
        expect(signOut.disabled).toBe(false)
        expect(signOut.getAttribute('aria-disabled')).toBe('true')
        expect(signOut.getAttribute('aria-busy')).toBe('true')

        await view.flush(() => signOut.click())
        expect(session.sent).toHaveLength(1)
        // Only the ack: no clearing handshake is supplied in this case.
        await view.flush(() => session.succeed(requestId))
        expect(signOut.disabled).toBe(false)
        expect(signOut.getAttribute('aria-disabled')).toBeNull()
        expect(signOut.getAttribute('aria-busy')).toBeNull()
      })
    })

    it('BC04 gives the page back when a handshake clears the card', async () => {
      await withLayout(BLOCKED_GREETING, async (view, session) => {
        await view.flush(() =>
          session.handshake({ data: { accountBlocked: null } }),
        )
        expect(part(view.root, 'account-blocked')).toBeNull()
        expect(part(view.root, 'page-body')).not.toBeNull()
      })
    })

    it('BC05 gives way to the maintenance surface', async () => {
      await withLayout(
        BLOCKED_GREETING,
        ({ root }) => {
          expect(part(root, 'account-blocked')).toBeNull()
        },
        { connection: layoutChromeConnection(FROZEN) },
      )
    })
  })
}
