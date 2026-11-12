import { hilosToasts, SIGN_OUT_ACTION } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  FROZEN,
  layoutChromeConnection,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import { layoutSessionControlsSession } from './layoutSessionControlsFixtures.js'

type LayoutSignOutRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}
type Session = Awaited<ReturnType<typeof layoutSessionControlsSession>>

const ANON = { entities: { currentUser: null } }
const EMPTY_NAME = { entities: { currentUser: { id: 1, name: '' } } }
const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}
const ANON_AFTER = { entities: { currentUser: null, impersonatedBy: null } }

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Shared sign-out behavior over raw session inputs and each SDK's real Layout.
 *
 * @param sdk SDK name shown in the local test report.
 * @param runner The SDK's local Vitest functions and optional React act wrapper.
 * @param mount The SDK's native Layout mount.
 */
export function registerLayoutSignOutCases(
  sdk: string,
  runner: LayoutSignOutRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner

  async function withLayout(
    greeting: Record<string, unknown>,
    steps: (view: LayoutChromeView, session: Session) => void | Promise<void>,
    input: LayoutChromeInput = { connection: layoutChromeConnection() },
  ): Promise<void> {
    const session = await layoutSessionControlsSession()
    let view: LayoutChromeView | undefined
    try {
      session.handshake(greeting)
      view = await mount(input)
      await steps(view, session)
    } finally {
      try {
        await view?.unmount()
      } finally {
        try {
          if (runner.releaseBindings)
            await runner.releaseBindings(session.release)
          else await session.release()
        } finally {
          hilosToasts.clear()
        }
      }
    }
  }

  describe(`HilosLayout sign-out on ${sdk}`, () => {
    it('S01 hides the control for an anonymous session', async () => {
      await withLayout(ANON, ({ root }) => {
        expect(part(root, 'nav-logout')).toBeNull()
      })
    })

    it.each([
      { id: 'S02a', greeting: EMPTY_NAME },
      { id: 'S02b', greeting: TAKEOVER },
    ])('$id keeps a named control for its identity', async ({ greeting }) => {
      await withLayout(greeting, ({ root }) => {
        const button = part(root, 'nav-logout') as HTMLButtonElement
        expect(button.tagName).toBe('BUTTON')
        expect(button.getAttribute('aria-label')).toBe('Sign out')
        expect(button.title).toBe('Sign out')
        expect(part(root, 'conn-state')).not.toBeNull()
        expect(
          button
            .querySelector('.bi-box-arrow-right')
            ?.getAttribute('aria-hidden'),
        ).toBe('true')
      })
    })

    it('S03 hides the control under maintenance', async () => {
      await withLayout(
        TAKEOVER,
        ({ root }) => {
          expect(part(root, 'nav-logout')).toBeNull()
        },
        { connection: layoutChromeConnection(FROZEN) },
      )
    })

    it('S04 hides the control while the first frame is held', async () => {
      const connection = layoutChromeConnection()
      Object.defineProperty(connection, 'firstFrameHeld', { value: true })
      await withLayout(
        TAKEOVER,
        ({ root }) => {
          expect(part(root, 'nav-logout')).toBeNull()
        },
        { connection },
      )
    })

    it('S05 tracks one sign-out until its silent success', async () => {
      await withLayout(TAKEOVER, async (view, session) => {
        const button = part(view.root, 'nav-logout') as HTMLButtonElement
        await view.flush(() => button.click())
        expect(button.disabled).toBe(false)
        expect(button.getAttribute('aria-disabled')).toBe('true')
        expect(button.getAttribute('aria-busy')).toBe('true')
        expect(part(view.root, 'loading-button-spinner')).toBeNull()

        await view.flush(() => button.click())
        expect(session.sent).toEqual([
          { action: 'hilos_logout', data: {}, requestId: expect.any(String) },
        ])
        const requestId = session.sent[0]?.requestId
        expect(requestId).toBeTruthy()

        await view.flush(() => session.succeed(requestId, SIGN_OUT_ACTION))
        const readyButton = part(view.root, 'nav-logout') as HTMLButtonElement
        expect(readyButton).toBe(button)
        expect(readyButton.disabled).toBe(false)
        expect(readyButton.getAttribute('aria-disabled')).toBeNull()
        expect(readyButton.getAttribute('aria-busy')).toBeNull()
        expect(hilosToasts.toasts.get()).toEqual([])
      })
    })

    it('S06 keeps the control and toasts the exact refusal', async () => {
      await withLayout(TAKEOVER, async (view, session) => {
        const button = part(view.root, 'nav-logout') as HTMLButtonElement
        await view.flush(() => button.click())
        await view.flush(() =>
          session.refuse(
            session.sent[0]?.requestId,
            'Session not on connection',
            SIGN_OUT_ACTION,
          ),
        )

        expect(part(view.root, 'nav-logout')).toBe(button)
        expect(button.disabled).toBe(false)
        expect(part(view.root, 'impersonation-banner')?.textContent).toContain(
          'Bob',
        )
        expect(
          hilosToasts.toasts
            .get()
            .map((toast) => [toast.severity, toast.message]),
        ).toEqual([['error', 'Session not on connection']])
      })
    })

    it('S07 stays silent when identity becomes anonymous before the ack', async () => {
      await withLayout(TAKEOVER, async (view, session) => {
        const button = part(view.root, 'nav-logout') as HTMLButtonElement
        await view.flush(() => button.click())
        const requestId = session.sent[0]?.requestId
        expect(requestId).toBeTruthy()

        await view.flush(() => session.handshake(ANON_AFTER))
        expect(part(view.root, 'nav-logout')).toBeNull()

        await view.flush(() => session.succeed(requestId, SIGN_OUT_ACTION))
        expect(hilosToasts.toasts.get()).toEqual([])
      })
    })
  })
}
