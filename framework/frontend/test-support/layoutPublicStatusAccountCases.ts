import {
  DEFAULT_IMPERSONATION_POLICY,
  HilosPages,
  offsetMs,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { BLOCKED, reconsentHandshake } from './layoutLifetimeSessionFixtures.js'
import { readLayoutPublicStatusDay } from './layoutPublicStatusColdCases.js'
import {
  withLayoutPublicStatus,
  type MountLayoutPublicStatus,
} from './layoutPublicStatusFixtures.js'
import { layoutStandingGreeting } from './layoutStandingFixtures.js'

const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing public Status element: ${id}`)
  return element
}

/** Account restrictions release only public reading, never shell requests. */
export function registerLayoutPublicStatusAccountCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>,
  mount: MountLayoutPublicStatus,
): void {
  const { describe, it, expect, vi } = runner

  describe(`HilosLayout public Status account and shell guards on ${sdk}`, () => {
    it.each(['blocked', 'frozen', 'takeover'] as const)(
      'ACCOUNT keeps public reading under %s and restores the ordinary account restriction',
      async (standing) => {
        await withLayoutPublicStatus(
          vi,
          mount,
          {
            state: 'disconnected',
            initialHandshake: () =>
              standing === 'blocked'
                ? BLOCKED
                : standing === 'frozen'
                  ? reconsentHandshake({ frozen: true })
                  : {
                      ...TAKEOVER,
                      data: {
                        impersonationPolicy: {
                          ...DEFAULT_IMPERSONATION_POLICY,
                          viewOnly: true,
                        },
                      },
                    },
          },
          async (scope) => {
            const { view, session, route, commit } = scope
            await readLayoutPublicStatusDay(expect, scope)
            expect(
              view.root.querySelector('[data-id="account-blocked"]'),
            ).toBeNull()
            expect(
              view.root.querySelector('[data-id="legal-frozen-screen"]'),
            ).toBeNull()
            if (standing === 'takeover') {
              const stop = get<HTMLButtonElement>(
                view.root,
                'impersonation-stop',
              )
              expect(stop.disabled).toBe(true)
              expect(
                get<HTMLButtonElement>(view.root, 'nav-logout').disabled,
              ).toBe(true)
              await commit(() => stop.click())
              expect(session.source.sent).toHaveLength(0)
            }
            await commit(() =>
              route.set({ page: 'ordinary', params: {}, admin: false }),
            )
            expect(
              get<HTMLFieldSetElement>(view.root, 'page-interaction-scope')
                .disabled,
            ).toBe(true)
            if (standing !== 'takeover') {
              expect(
                view.root.querySelector(
                  `[data-id="${standing === 'blocked' ? 'account-blocked' : 'legal-frozen-screen'}"]`,
                ),
              ).not.toBeNull()
            }
            await commit(() =>
              route.set({
                page: HilosPages.PUBLIC_STATUS,
                params: {},
                admin: false,
              }),
            )
            await readLayoutPublicStatusDay(expect, scope)
          },
        )
      },
    )

    it.each([
      'nav-logout',
      'impersonation-stop',
      'account-deletion-strip-keep',
    ] as const)(
      'GUARDS lets the file reader interact offline while %s dispatches nothing',
      async (id) => {
        const entryOffset = offsetMs()
        try {
          await withLayoutPublicStatus(vi, mount, {}, async (scope) => {
            const { view, session, feed, commit } = scope
            // This family receives identity after the public shell has mounted.
            await commit(() =>
              session.handshake(
                id === 'impersonation-stop'
                  ? TAKEOVER
                  : layoutStandingGreeting(
                      'deletion_scheduled',
                      false,
                      Date.now() + 86_400_000,
                    ),
              ),
            )
            const button = view.root.querySelector<HTMLButtonElement>(
              `[data-id="${id}"]`,
            )!
            expect(button).not.toBeNull()
            await commit(() => {
              feed.state('disconnected')
              // Native click before the framework commits its disabled binding.
              button.click()
            })
            button.click()
            expect(session.source.sent).toEqual([])
            expect(button.disabled).toBe(true)
            expect(
              get(view.root, 'page-interaction-scope').matches(':disabled'),
            ).toBe(false)
            const day = get(view.root, 'public-status-days-90').querySelector(
              'button',
            )!
            await commit(() => day.focus())
            expect(
              get(view.root, 'public-status-day-detail').textContent?.trim(),
            ).toBe(day.getAttribute('aria-label'))
          })
        } finally {
          // A deletion greeting changes the module clock; every exit restores it.
          expect(offsetMs()).toBe(entryOffset)
        }
      },
    )
  })
}
