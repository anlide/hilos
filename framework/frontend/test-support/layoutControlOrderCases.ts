import type { HilosRouter } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  layoutChromeConnection,
  layoutChromeRouter,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'

type LayoutControlOrderRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
>

type LayoutControlOrderSession = {
  handshake(payload: Record<string, unknown>): void
  unbind(): void | Promise<void>
}

type BindLayoutControlOrderSession = (
  router: HilosRouter,
) => LayoutControlOrderSession

const CONTROL_ORDER_CASES = [
  [
    'guest',
    {
      data: { adminViewMode: false, accountStanding: null },
      entities: { currentUser: null, impersonatedBy: null },
    },
    false,
    false,
    false,
  ],
  [
    'signed',
    {
      data: {
        adminViewMode: false,
        accountStanding: {
          shown: 'none',
          blocked: false,
          frozen: false,
          deletionEffectiveAt: null,
          lapsed: [],
          window: [{ document: 'terms', deadline: '2099-11-10' }],
          mergedInto: null,
          mergedIntoName: null,
        },
      },
      entities: {
        currentUser: { id: 2, name: 'Bob' },
        impersonatedBy: null,
      },
    },
    false,
    true,
    true,
  ],
  [
    'admin',
    {
      data: {
        adminViewMode: false,
        accountStanding: {
          shown: 'none',
          blocked: false,
          frozen: false,
          deletionEffectiveAt: null,
          lapsed: [],
          window: [{ document: 'terms', deadline: '2099-11-10' }],
          mergedInto: null,
          mergedIntoName: null,
        },
      },
      entities: {
        currentUser: { id: 2, name: 'Bob', admin: true },
        impersonatedBy: null,
      },
    },
    true,
    true,
    true,
  ],
  [
    'view',
    {
      data: { adminViewMode: true, accountStanding: null },
      entities: { currentUser: null, impersonatedBy: null },
    },
    true,
    false,
    false,
  ],
  [
    'takeover',
    {
      data: {
        adminViewMode: false,
        accountStanding: {
          shown: 'none',
          blocked: false,
          frozen: false,
          deletionEffectiveAt: null,
          lapsed: [],
          window: [{ document: 'terms', deadline: '2099-11-10' }],
          mergedInto: null,
          mergedIntoName: null,
        },
      },
      entities: {
        currentUser: { id: 2, name: 'Bob' },
        impersonatedBy: { id: 1, name: 'Ada' },
      },
    },
    false,
    true,
    false,
  ],
] as const

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Keep the shell's real control DOM order across its five session states. */
export function registerLayoutControlOrderCases(
  sdk: string,
  runner: LayoutControlOrderRunner,
  mount: MountLayoutChrome,
  bindSession: BindLayoutControlOrderSession,
): void {
  const { describe, it, expect } = runner

  describe(`HilosLayout control order on ${sdk} (HIL-1647)`, () => {
    it.each(CONTROL_ORDER_CASES)(
      'keeps actual control DOM order for %s',
      async (
        caseName,
        payload,
        expectedAdmin,
        expectedLogout,
        expectedReconsent,
      ) => {
        let session: LayoutControlOrderSession | undefined
        let view: LayoutChromeView | undefined
        const errors: unknown[] = []

        try {
          const { router } = layoutChromeRouter()
          session = bindSession(router)
          session.handshake(payload)
          view = await mount({
            connection: layoutChromeConnection(),
            nav: true,
            projectUserControls: true,
            router,
          })
          await view.flush()

          const root = view.root
          const get = (id: string): HTMLElement => {
            const element = part(root, id)
            expect(element, id).not.toBeNull()
            return element!
          }
          const ids = [
            'nav-theme',
            'nav-admin',
            'user-bell',
            'user-avatar',
            'user-signin',
            'legal-reconsent-icon',
            'nav-logout',
            'nav-toggle',
            'conn-state',
          ]

          for (const id of [
            'nav-theme',
            'user-bell',
            'user-avatar',
            'user-signin',
            'nav-toggle',
            'conn-state',
          ]) {
            get(id)
          }

          const present = ids
            .map((id) => part(root, id))
            .filter((element): element is HTMLElement => element !== null)
          for (let index = 1; index < present.length; index++) {
            expect(
              present[index - 1]!.compareDocumentPosition(present[index]!) &
                Node.DOCUMENT_POSITION_FOLLOWING,
            ).not.toBe(0)
          }
          expect(get('conn-state').parentElement?.lastElementChild).toBe(
            get('conn-state'),
          )
          expect(part(root, 'nav-admin') !== null).toBe(expectedAdmin)
          expect(part(root, 'nav-logout') !== null).toBe(expectedLogout)
          expect(part(root, 'legal-reconsent-icon') !== null).toBe(
            expectedReconsent,
          )
        } catch (error) {
          errors.push(error)
        } finally {
          try {
            await view?.unmount()
          } catch (error) {
            errors.push(error)
          }
          try {
            await session?.unbind()
          } catch (error) {
            errors.push(error)
          }
        }

        if (errors.length === 1) throw errors[0]
        if (errors.length > 1) {
          throw new AggregateError(
            errors,
            `Control order case ${caseName} and cleanup failed`,
          )
        }
      },
    )
  })
}
