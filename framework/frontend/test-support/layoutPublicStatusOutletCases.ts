import {
  hilosAccessLossReason,
  rtStalenessCompactLabel,
  type RtStalenessStatus,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { BLOCKED, reconsentHandshake } from './layoutLifetimeSessionFixtures.js'
import {
  withLayoutPublicStatus,
  type MountLayoutPublicStatus,
} from './layoutPublicStatusFixtures.js'

const OUTLET_STATES = [
  'blocked',
  'frozen',
  'stale',
  'takeover',
  'session_ended',
  'account_blocked',
] as const

const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}

function find<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T | null {
  return root.querySelector<T>(`[data-id="${id}"]`)
}

/** Share the public reader's account, access-loss, and departure outcomes. */
export function registerLayoutPublicStatusOutletCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>,
  mount: MountLayoutPublicStatus,
): void {
  const { describe, it, expect, vi } = runner

  describe(`HilosLayout public Status outlet on ${sdk}`, () => {
    it.each(OUTLET_STATES)(
      'keeps the real public reader mounted under %s and restores the other route policy',
      async (state) => {
        await withLayoutPublicStatus(vi, mount, {}, async (scope) => {
          const { view, session, feed, route, commit } = scope
          await commit(() =>
            session.handshake({
              entities: { currentUser: { id: 2, name: 'Bob' } },
              data: { accountBlocked: null },
            }),
          )
          const banner = find(view.root, 'public-status-banner')
          expect(banner).not.toBeNull()
          expect(banner!.textContent).toContain('All systems operational')

          const stale: RtStalenessStatus = { stale: true, since: Date.now() }
          await commit(() => {
            switch (state) {
              case 'blocked':
                session.handshake(BLOCKED)
                break
              case 'frozen':
                session.handshake(reconsentHandshake({ frozen: true }))
                break
              case 'stale':
                feed.rtStaleness(stale)
                break
              case 'takeover':
                session.handshake(TAKEOVER)
                break
              case 'session_ended':
              case 'account_blocked':
                session.handshake({
                  entities: { currentUser: null },
                  data: {
                    accessLossReason: state,
                    accountBlocked:
                      state === 'account_blocked'
                        ? BLOCKED.data.accountBlocked
                        : null,
                  },
                })
                break
            }
          })

          expect(banner!.isConnected).toBe(true)
          expect(banner!.textContent).toContain('All systems operational')
          expect(find(view.root, 'account-blocked')).toBeNull()
          expect(find(view.root, 'legal-frozen-screen')).toBeNull()
          expect(find(view.root, 'access-loss-notice')).toBeNull()
          expect(find(view.root, 'test-sign-in')).toBeNull()
          const guest =
            state === 'blocked' ||
            state === 'session_ended' ||
            state === 'account_blocked'
          expect(find(view.root, 'nav-logout') !== null).toBe(!guest)
          if (state === 'stale') {
            expect(
              find(view.root, 'conn-stale-line')?.textContent?.trim(),
            ).toBe(rtStalenessCompactLabel(stale))
          }
          if (state === 'takeover') {
            expect(find(view.root, 'impersonation-banner')).not.toBeNull()
          }
          if (state === 'session_ended' || state === 'account_blocked') {
            expect(hilosAccessLossReason.get()).toBe(state)
            expect(document.activeElement).toBe(document.body)
          }

          // Changing only the page key preserves the session-ended latch.
          await commit(() =>
            route.set({ page: 'main', params: {}, admin: false }),
          )
          expect(banner!.isConnected).toBe(false)
          expect(find(view.root, 'public-status-banner')).toBeNull()
          if (state === 'blocked' || state === 'account_blocked') {
            expect(find(view.root, 'account-blocked')).not.toBeNull()
            expect(find(view.root, 'test-sign-in')).toBeNull()
          }
          if (state === 'frozen') {
            expect(find(view.root, 'legal-frozen-screen')).not.toBeNull()
          }
          if (state === 'session_ended') {
            expect(hilosAccessLossReason.get()).toBe('session_ended')
            // Angular retains template indentation around this exact copy.
            expect(
              find(view.root, 'access-loss-notice')?.textContent?.trim(),
            ).toBe('Your session has ended. Any unsaved changes were lost.')
            expect(find(view.root, 'test-sign-in')).not.toBeNull()
            expect(find(view.root, 'ordinary-page')).toBeNull()
          }
          if (state === 'stale' || state === 'takeover') {
            expect(
              find(view.root, 'page-interaction-scope')?.querySelector(
                '[data-id="ordinary-page"]',
              ),
            ).not.toBeNull()
            expect(find(view.root, 'access-loss-notice')).toBeNull()
            expect(find(view.root, 'test-sign-in')).toBeNull()
          }
          if (state === 'stale') {
            expect(
              find(view.root, 'conn-stale-line')?.textContent?.trim(),
            ).toBe(rtStalenessCompactLabel(stale))
          }
          if (state === 'takeover') {
            expect(find(view.root, 'impersonation-banner')).not.toBeNull()
          }
        })
      },
    )
  })
}
