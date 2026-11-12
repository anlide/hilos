import {
  createSignal,
  hilosToasts,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  BLOCKED_GREETING,
  layoutBlockedSession,
} from './layoutBlockedFixtures.js'
import { layoutChromeConnection } from './layoutChromeFixtures.js'

/** Native page hosts consume the same navigator as the session's access reaction. */
export interface LayoutBlockedInput {
  connection: HilosConnection
  router: HilosRouter
}

/** Only native rendering and lifecycle; all business steps stay in the cases. */
export interface LayoutBlockedView {
  readonly root: HTMLElement
  flush(run?: () => void): Promise<void>
  unmount(): Promise<void>
}

export type MountLayoutBlocked = (
  input: LayoutBlockedInput,
) => Promise<LayoutBlockedView>

type BlockedRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}
type Session = Awaited<ReturnType<typeof layoutBlockedSession>>

/** Eager main-page input, without a transport or page-subscription simulation. */
function layoutBlockedRouter(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: 'main',
      params: {},
      admin: false,
    }),
    currentPath: createSignal('/'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => (page === 'main' ? '/' : undefined),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** The raw frame explains this live identity loss, before session ingestion. */
const LIVE_BLOCK = {
  entities: { currentUser: null },
  data: {
    accountBlocked: { identifier: 'maria@example.com' },
    accessLossReason: 'account_blocked',
  },
}

/** Shared card/latch behavior over real page and modal lifetimes in each SDK. */
export function registerLayoutBlockedLiveCases(
  sdk: string,
  runner: BlockedRunner,
  mount: MountLayoutBlocked,
): void {
  const { describe, it, expect } = runner

  async function withLayout(
    greeting: Record<string, unknown>,
    steps: (view: LayoutBlockedView, session: Session) => void | Promise<void>,
  ): Promise<void> {
    let session: Session | undefined
    let view: LayoutBlockedView | undefined
    try {
      const router = layoutBlockedRouter()
      session = await layoutBlockedSession(router)
      session.handshake(greeting)
      view = await mount({ connection: layoutChromeConnection(), router })
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

  describe(`HilosLayout live blocked card on ${sdk}`, () => {
    it('BC06 keeps a cold blocked page host free of a live-loss warning', async () => {
      await withLayout(BLOCKED_GREETING, ({ root }) => {
        expect(part(root, 'account-blocked')).not.toBeNull()
        expect(part(root, 'access-loss-notice')).toBeNull()
      })
    })

    it('AL01 destroys the dirty dialog and focuses the explained live-block card', async () => {
      await withLayout(
        { entities: { currentUser: { id: 7 } } },
        async (view, session) => {
          const { root } = view
          const document = root.ownerDocument
          const opener = part(root, 'open-draft')!
          opener.focus()
          await view.flush(() => opener.click())
          const draft =
            document.querySelector<HTMLInputElement>('[data-id="draft"]')!
          draft.value = 'Unsaved phone number'
          expect(document.activeElement).toBe(draft)

          await view.flush(() => session.handshake(LIVE_BLOCK))
          const card = part(root, 'account-blocked')!
          const notice = part(card, 'access-loss-notice')!
          expect(notice.textContent?.trim()).toBe(
            'Any unsaved changes were lost.',
          )
          expect(notice.parentElement?.getAttribute('role')).toBe('status')
          expect(notice.parentElement?.getAttribute('aria-live')).toBe('polite')
          expect(part(root, 'private-content')).toBeNull()
          expect(part(root, 'hilos-page-departure')).toBeNull()
          expect(draft.isConnected).toBe(false)
          expect(part(document, 'modal')).toBeNull()
          expect(part(document, 'modal-confirm')).toBeNull()
          expect(document.activeElement).toBe(card.querySelector('section'))
          expect(root.querySelector('nav')).not.toBeNull()
          expect(part(root, 'app-footer')).not.toBeNull()

          await view.flush(() => session.handshake(BLOCKED_GREETING))
          expect(part(card, 'access-loss-notice')?.textContent?.trim()).toBe(
            'Any unsaved changes were lost.',
          )
        },
      )
    })

    it.each(['sign out', 'unblock'] as const)(
      'AL02 clears the live warning on %s and leaves the next cold card unexplained',
      async (exit) => {
        await withLayout(
          { entities: { currentUser: { id: 7 } } },
          async (view, session) => {
            const { root } = view
            await view.flush(() => session.handshake(LIVE_BLOCK))
            expect(part(root, 'access-loss-notice')).not.toBeNull()

            if (exit === 'sign out') {
              await view.flush(() =>
                part(root, 'account-blocked-sign-out')!.click(),
              )
              expect(session.sent[0]?.action).toBe(
                'hilos_dismiss_account_blocked',
              )
              await view.flush(() =>
                session.succeed(session.sent[0]?.requestId),
              )
              await view.flush(() =>
                session.handshake({
                  entities: { currentUser: null },
                  data: { accountBlocked: null },
                }),
              )
            } else {
              await view.flush(() =>
                session.handshake({
                  entities: { currentUser: { id: 7 } },
                  data: { accountBlocked: null },
                }),
              )
            }
            expect(part(root, 'account-blocked')).toBeNull()
            expect(part(root, 'access-loss-notice')).toBeNull()
            expect(part(root, 'private-content')).not.toBeNull()

            await view.flush(() => session.handshake(BLOCKED_GREETING))
            expect(part(root, 'account-blocked')).not.toBeNull()
            expect(part(root, 'access-loss-notice')).toBeNull()
          },
        )
      },
    )

    it('AL03 does not carry an ended-session explanation into a newly refused blocked sign-in', async () => {
      await withLayout(
        { entities: { currentUser: { id: 7 } } },
        async (view, session) => {
          await view.flush(() =>
            session.handshake({
              entities: { currentUser: null },
              data: { accessLossReason: 'session_ended' },
            }),
          )
          expect(
            part(view.root, 'access-loss-notice')?.textContent?.trim(),
          ).toBe('Your session has ended. Any unsaved changes were lost.')
          expect(part(view.root, 'test-sign-in')).not.toBeNull()

          await view.flush(() => session.handshake(BLOCKED_GREETING))
          expect(part(view.root, 'account-blocked')).not.toBeNull()
          expect(part(view.root, 'test-sign-in')).toBeNull()
          expect(part(view.root, 'access-loss-notice')).toBeNull()
          expect(view.root.textContent).not.toContain(
            'Any unsaved changes were lost.',
          )
        },
      )
    })
  })
}
