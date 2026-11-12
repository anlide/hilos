// A single union of session-loss steps and expectations on three real SDKs.
import {
  DEFAULT_SKELETON_DELAY_MS,
  hilosAccessLossReason,
  HilosPages,
  lazyPage,
  resolveHilosPath,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

import {
  CARD_DELETED,
  CARD_IDENTITY,
  CARD_NOT_FOUND,
} from './hilosViewDeletionFixtures.js'
import {
  accessSession,
  type AccessOutlet,
  type HilosViewAccessKit,
  type WithHilosViewAccess,
} from './hilosViewAccessFixtures.js'

type AccessRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>

/** Local lifetime choices wrap the same X01 body, including React StrictMode. */
export function registerHilosViewAccessCases<T>(
  sdk: string,
  { describe, it, expect, vi }: AccessRunner,
  withKit: WithHilosViewAccess<T>,
  withDirtyDialog: WithHilosViewAccess<T> = withKit,
): void {
  const find = (view: AccessOutlet, id: string) =>
    view.root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  const doc = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  const authCount = () =>
    document.querySelectorAll('[data-id="auth-surface"]').length
  const notice = (view: AccessOutlet) => find(view, 'access-loss-notice')
  const run = async (
    steps: (
      kit: HilosViewAccessKit<T>,
      session: ReturnType<typeof accessSession>,
      ownNode: (node: HTMLElement) => HTMLElement,
    ) => Promise<void>,
    initial = '/',
    lifetime = withKit,
  ) => {
    vi.useFakeTimers()
    const session = accessSession(initial)
    const nodes: HTMLElement[] = []
    try {
      await lifetime(
        (kit) =>
          steps(kit, session, (node) => {
            nodes.push(node)
            return document.body.appendChild(node)
          }),
        session.release,
      )
    } finally {
      for (const node of nodes) node.remove()
      vi.restoreAllMocks()
      vi.useRealTimers()
    }
  }
  const render = (
    kit: HilosViewAccessKit<T>,
    session: ReturnType<typeof accessSession>,
    auth = true,
    retained = false,
  ) =>
    kit.mount({
      router: session.router,
      pages: {
        main: kit.dirty,
        other: kit.known,
        lazy: retained
          ? lazyPage(() => Promise.resolve(kit.card(true).component))
          : kit.dirty,
        [HilosPages.PUBLIC_STATUS]: kit.status,
      },
      ...(auth ? { authSurface: kit.auth, authGate: session.gate } : {}),
    })
  const outlast = (view: AccessOutlet) =>
    view.flush(() => vi.advanceTimersByTime(DEFAULT_SKELETON_DELAY_MS))
  const openDraft = async (view: AccessOutlet) => {
    const opener = find(view, 'open-draft')!
    await view.flush(() => {
      opener.focus()
      opener.click()
    })
    const draft = doc('draft') as HTMLInputElement
    await view.type(draft, 'Unsaved phone number')
    expect(draft.value).toBe('Unsaved phone number')
    expect(document.activeElement).toBe(draft)
    return draft
  }

  describe(`HilosView live access loss on ${sdk}`, () => {
    it('X01 destroys a dirty dialog, focuses sole in-place auth and survives anonymous reconnect', async () => {
      await run(
        async (kit, session) => {
          session.handshake(7)
          session.answer()
          const view = await render(kit, session)
          const region =
            view.root.querySelector<HTMLElement>('[role="status"]')!
          expect(region).not.toBeNull()
          const draft = await openDraft(view)
          const sentBefore = session.send.mock.calls.length
          await view.flush(() => {
            session.handshake(null, { accessLossReason: 'session_ended' })
            session.gate.requireAuth()
          })
          expect(notice(view)?.textContent?.trim()).toBe(
            'Your session has ended. Any unsaved changes were lost.',
          )
          expect(notice(view)?.parentElement).toBe(region)
          expect(region.getAttribute('role')).toBe('status')
          expect(region.getAttribute('aria-live')).toBe('polite')
          expect(find(view, 'private-page')).toBeNull()
          expect(draft.isConnected).toBe(false)
          expect(doc('modal')).toBeNull()
          expect(doc('modal-confirm')).toBeNull()
          expect(
            view.root.querySelectorAll('[data-id="auth-surface"]'),
          ).toHaveLength(1)
          expect(authCount()).toBe(1)
          expect(document.activeElement).toBe(find(view, 'auth-identifier'))
          expect(session.send).toHaveBeenCalledTimes(sentBefore)
          expect(session.router.currentPath.get()).toBe('/')
          await view.flush(() => {
            session.handshake(null)
            session.answer()
          })
          await outlast(view)
          expect(notice(view)?.textContent?.trim()).toBe(
            'Your session has ended. Any unsaved changes were lost.',
          )
          expect(find(view, 'private-page')).toBeNull()
          expect(find(view, 'hilos-page-skeleton')).toBeNull()
          expect(authCount()).toBe(1)
          await view.flush(() => {
            session.handshake(8)
            session.answer()
          })
          expect(find(view, 'private-page')).not.toBeNull()
          expect(notice(view)).toBeNull()
          expect(authCount()).toBe(0)
        },
        '/',
        withDirtyDialog,
      )
    })

    it('X02 replaces an open action-auth modal with one in-place form', async () => {
      await run(async (kit, session) => {
        session.handshake(7)
        session.answer()
        const view = await render(kit, session)
        await view.flush(() => session.gate.requireAuth())
        expect(doc('modal')).not.toBeNull()
        expect(authCount()).toBe(1)
        await view.flush(() =>
          session.handshake(null, { accessLossReason: 'session_ended' }),
        )
        expect(doc('modal')).toBeNull()
        expect(
          view.root.querySelectorAll('[data-id="auth-surface"]'),
        ).toHaveLength(1)
        expect(authCount()).toBe(1)
        expect(document.activeElement).toBe(find(view, 'auth-identifier'))
        expect(notice(view)?.textContent?.trim()).toBe(
          'Your session has ended. Any unsaved changes were lost.',
        )
      })
    })

    it.each(['session_ended', 'account_blocked'] as const)(
      'X03 clears a nonempty copy before framework flush on %s and never copies the lost page',
      async (reason) => {
        await run(async (kit, session) => {
          session.handshake(7)
          session.answer()
          const view = await render(kit, session)
          const copy = find(view, 'hilos-page-departure')!
          await view.flush(() => {
            session.router.navigate('/other')
            expect(copy.textContent?.trim()).toBe('Edit')
            expect(copy.childElementCount).toBe(2)
            session.handshake(null, {
              accessLossReason: reason,
              accountBlocked:
                reason === 'account_blocked'
                  ? { identifier: 'ada@example.com' }
                  : null,
            })
            expect(copy.childElementCount).toBe(0)
          })
          await view.flush(() => session.answer())
          await view.flush(() => {
            session.router.navigate('/')
            expect(copy.childElementCount).toBe(0)
          })
          expect(notice(view)).toBeNull()
          await view.flush(() => session.answer())
          expect(find(view, 'private-page')).not.toBeNull()
        })
      },
    )

    it.each(['loading', 'error', 'retained'] as const)(
      'X04 gives session loss priority over %s and a later guest answer',
      async (state) => {
        await run(
          async (kit, session) => {
            session.handshake(7)
            if (state !== 'loading')
              session.answer(state === 'retained' ? CARD_IDENTITY : undefined)
            const view = await render(kit, session, true, state === 'retained')
            if (state === 'error')
              await view.flush(() => session.refuse(CARD_NOT_FOUND))
            if (state === 'retained') {
              await view.flush(() => session.refuse(CARD_DELETED))
              expect(find(view, 'hilos-admin-page')).not.toBeNull()
              expect(find(view, 'page-error')).not.toBeNull()
            }
            await view.flush(() =>
              session.handshake(null, { accessLossReason: 'session_ended' }),
            )
            await outlast(view)
            for (const id of [
              'page-error',
              'hilos-page-skeleton',
              'hilos-admin-page',
              'private-page',
            ]) {
              expect(find(view, id)).toBeNull()
            }
            expect(notice(view)).not.toBeNull()
            expect(
              view.root.querySelectorAll('[data-id="auth-surface"]'),
            ).toHaveLength(1)
            expect(authCount()).toBe(1)
            await view.flush(() => session.answer())
            expect(find(view, 'private-page')).toBeNull()
            expect(notice(view)).not.toBeNull()
          },
          state === 'retained' ? '/lazy/1' : '/',
        )
      },
    )

    it('X05 keeps the real public Status entry despite a raised session-loss latch and guest answer', async () => {
      await run(async (kit, session) => {
        session.handshake(7)
        session.answer()
        const view = await render(kit, session)
        const reader = find(view, 'public-file-reader')
        expect(reader).not.toBeNull()
        await view.flush(() =>
          session.handshake(null, { accessLossReason: 'session_ended' }),
        )
        await outlast(view)
        expect(hilosAccessLossReason.get()).toBe('session_ended')
        expect(find(view, 'public-file-reader')).toBe(reader)
        for (const id of [
          'access-loss-notice',
          'auth-surface',
          'page-error',
          'hilos-page-skeleton',
          'hilos-admin-page',
        ]) {
          expect(find(view, id)).toBeNull()
        }
        expect(authCount()).toBe(0)
        await view.flush(() => session.answer())
        expect(find(view, 'public-file-reader')).toBe(reader)
        expect(hilosAccessLossReason.get()).toBe('session_ended')
        for (const id of [
          'access-loss-notice',
          'auth-surface',
          'page-error',
          'hilos-page-skeleton',
          'hilos-admin-page',
        ]) {
          expect(find(view, id)).toBeNull()
        }
      }, resolveHilosPath(HilosPages.PUBLIC_STATUS)!)
    })

    it('X06 explains loss without inventing auth and focuses the available container', async () => {
      await run(async (kit, session) => {
        session.handshake(7)
        session.answer()
        const view = await render(kit, session, false)
        const draft = await openDraft(view)
        await view.flush(() =>
          session.handshake(null, { accessLossReason: 'session_ended' }),
        )
        expect(draft.isConnected).toBe(false)
        expect(doc('modal')).toBeNull()
        expect(find(view, 'private-page')).toBeNull()
        expect(authCount()).toBe(0)
        expect(notice(view)?.textContent?.trim()).toBe(
          'Your session has ended. Any unsaved changes were lost.',
        )
        const container = view.root.querySelector<HTMLElement>(
          '.hilos-page-slot > [tabindex="-1"]',
        )
        expect(container).not.toBeNull()
        expect(document.activeElement).toBe(container)
        await view.flush(() => session.answer())
        expect(find(view, 'private-page')).toBeNull()
      })
    })

    it.each([
      [
        'ended_by_administrator',
        'Your session was ended by an administrator. Any unsaved changes were lost.',
      ],
      [
        'ended_from_another_browser',
        'Your session was ended from another browser. Any unsaved changes were lost.',
      ],
      [
        'password_changed',
        'Your session has ended because the password was changed. Any unsaved changes were lost.',
      ],
      [
        'password_recovered',
        'Your session has ended because the password was reset. Any unsaved changes were lost.',
      ],
    ] as const)(
      'X09 shows the sign-in form and the explanation for %s',
      async (reason, copy) => {
        await run(async (kit, session) => {
          session.handshake(7)
          session.answer()
          const view = await render(kit, session)
          await view.flush(() =>
            session.handshake(null, { accessLossReason: reason }),
          )
          expect(notice(view)?.textContent?.trim()).toBe(copy)
          expect(find(view, 'private-page')).toBeNull()
          expect(
            view.root.querySelectorAll('[data-id="auth-surface"]'),
          ).toHaveLength(1)
          expect(authCount()).toBe(1)
          const form = view.root.querySelector<HTMLFormElement>(
            'form[data-id="auth-surface"]',
          )
          expect(form).not.toBeNull()
          expect(form?.hidden).toBe(false)
          const identifier = find(view, 'auth-identifier')
          expect(identifier).not.toBeNull()
          expect(document.activeElement).toBe(identifier)
        })
      },
    )

    it('X07 preserves a deliberate focus choice outside the replaced page', async () => {
      await run(async (kit, session, ownNode) => {
        session.handshake(7)
        session.answer()
        const view = await render(kit, session)
        const nav = ownNode(document.createElement('button'))
        nav.focus()
        expect(document.activeElement).toBe(nav)
        await view.flush(() =>
          session.handshake(null, { accessLossReason: 'session_ended' }),
        )
        expect(notice(view)).not.toBeNull()
        expect(document.activeElement).toBe(nav)
      })
    })

    it.each([
      'cold guest',
      'new blocked sign-in',
      'voluntary logout',
      'unknown',
      'unrecognized',
    ] as const)('X08 shows no loss notice for %s', async (kind) => {
      await run(async (kit, session) => {
        if (
          kind === 'voluntary logout' ||
          kind === 'unknown' ||
          kind === 'unrecognized'
        )
          session.handshake(7)
        const view = await render(kit, session)
        await view.flush(() => {
          session.handshake(
            null,
            kind === 'new blocked sign-in'
              ? { accountBlocked: { identifier: 'ada@example.com' } }
              : kind === 'unknown' || kind === 'unrecognized'
                ? { accessLossReason: kind }
                : {},
          )
          session.answer()
        })
        expect(hilosAccessLossReason.get()).toBeNull()
        expect(notice(view)).toBeNull()
      })
    })
  })
}
