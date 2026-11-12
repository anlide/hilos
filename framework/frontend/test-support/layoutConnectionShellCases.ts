import {
  applyServerTime,
  DEFAULT_IMPERSONATION_POLICY,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosToasts,
  offsetMs,
  type HilosConnection,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { layoutChromeFeed } from './layoutChromeFixtures.js'

type ShellControl =
  'nav-logout' | 'impersonation-stop' | 'account-deletion-strip-keep'

/** Native invocation only; the common case owns state changes and timing. */
export interface LayoutConnectionShellView {
  readonly root: HTMLElement
  invokeStale(id: ShellControl): void
  flush(run?: () => void): Promise<void>
  unmount(): void | Promise<void>
}

export type MountShell = (input: {
  connection: HilosConnection
  allow?: boolean
}) => LayoutConnectionShellView

export type ShellSession = {
  source: {
    readonly sent: readonly { action: string; requestId?: string }[]
    succeed(requestId: string | undefined, action: string): void
    disconnect(): void
  }
  handshake(payload: Record<string, unknown>): void
  unbind(): void | Promise<void>
}

const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}

function deletionHandshake(): Record<string, unknown> {
  return {
    data: {
      serverTimeMs: 1_800_000_000_000,
      accountStanding: {
        shown: 'deletion_scheduled',
        blocked: false,
        frozen: false,
        deletionEffectiveAt: Date.now() + 86_400_000,
        deletionGraceDays: null,
        lapsed: [],
        window: [],
        mergedInto: null,
        mergedIntoName: null,
      },
    },
    entities: {
      currentUser: { id: 2, name: 'Bob' },
      impersonatedBy: null,
    },
  }
}

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (element === null)
    throw new Error(`Missing connection shell control: ${id}`)
  return element
}

/** Settle requests while mounted, then release each resource independently. */
export async function isolated(
  bindSession: () => ShellSession,
  mount: MountShell,
  run: (session: ShellSession, retain: MountShell) => Promise<void>,
): Promise<void> {
  const entryOffset = offsetMs()
  let session: ShellSession | undefined
  let view: LayoutConnectionShellView | undefined
  const errors: unknown[] = []
  try {
    session = bindSession()
    await run(session, (input) => {
      view = mount(input)
      return view
    })
  } catch (error) {
    errors.push(error)
  } finally {
    try {
      // The action source is independent of the shell feed. Disconnect settles
      // only requests still pending, without replaying successful side effects.
      if (view !== undefined)
        await view.flush(() => session?.source.disconnect())
      else {
        session?.source.disconnect()
        await Promise.resolve()
      }
    } catch (error) {
      errors.push(error)
    }
    try {
      await session?.unbind()
    } catch (error) {
      errors.push(error)
    }
    try {
      await view?.unmount()
    } catch (error) {
      errors.push(error)
    }
    try {
      hilosToasts.clear()
    } catch (error) {
      errors.push(error)
    }
    try {
      applyServerTime(Date.now() + entryOffset)
    } catch (error) {
      errors.push(error)
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Connection shell case and cleanup failed')
}

/** The shell's request guard and independent disabled causes on real SDK hosts. */
export function registerLayoutConnectionShellCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect'>,
  mount: MountShell,
  bindSession: () => ShellSession,
): void {
  const { describe, it, expect } = runner
  describe(`HilosLayout connection shell on ${sdk}`, () => {
    it.each<ShellControl>([
      'nav-logout',
      'impersonation-stop',
      'account-deletion-strip-keep',
    ])(
      'C04 guards %s before render, independently of page opt-out and busy',
      async (id) => {
        await isolated(bindSession, mount, async (session, retain) => {
          session.handshake(
            id === 'impersonation-stop' ? TAKEOVER : deletionHandshake(),
          )
          const feed = layoutChromeFeed()
          const view = retain({ connection: feed.connection, allow: true })
          await view.flush()
          const button = get<HTMLButtonElement>(view.root, id)
          await view.flush(() => {
            feed.state('reconnecting')
            view.invokeStale(id)
            expect(session.source.sent).toHaveLength(0)
          })
          expect(button.disabled).toBe(true)
          expect(button.getAttribute('aria-busy')).toBeNull()
          expect(button.getAttribute('aria-describedby')).toBe(
            HILOS_CONNECTION_STATUS_TEXT_ID,
          )
          expect(
            get<HTMLFieldSetElement>(view.root, 'page-interaction-scope')
              .disabled,
          ).toBe(false)
          expect(
            get(view.root, 'nav-brand').hasAttribute('aria-disabled'),
          ).toBe(false)
          expect(
            get(view.root, 'app-footer')
              .querySelector('a')!
              .hasAttribute('aria-disabled'),
          ).toBe(false)
          await view.flush(() => button.click())
          expect(session.source.sent).toHaveLength(0)
          await view.flush(() => feed.state('connected'))
          await view.flush(() => button.click())
          expect(session.source.sent).toHaveLength(1)
          await view.flush(() => feed.state('disconnected'))
          await view.flush(() => feed.state('connected'))
          expect(button.getAttribute('aria-busy')).toBe('true')
          await view.flush(() => button.click())
          expect(session.source.sent).toHaveLength(1)
          await view.flush(() => {
            const sent = session.source.sent[0]!
            session.source.succeed(sent.requestId, sent.action)
          })
          expect(button.getAttribute('aria-busy')).toBeNull()
          expect(button.getAttribute('aria-disabled')).toBeNull()
          expect(hilosToasts.toasts.get()).toEqual([])
        })
      },
    )

    it('C05 releases only the connection cause while takeover restrictions still apply', async () => {
      await isolated(bindSession, mount, async (session, retain) => {
        session.handshake({
          ...TAKEOVER,
          data: {
            impersonationPolicy: {
              ...DEFAULT_IMPERSONATION_POLICY,
              viewOnly: true,
            },
          },
        })
        const feed = layoutChromeFeed()
        const view = retain({ connection: feed.connection })
        await view.flush()
        const action = get<HTMLButtonElement>(view.root, 'page-mutate')
        expect(action.disabled).toBe(true)
        await view.flush(() => feed.state('reconnecting'))
        expect(action.getAttribute('aria-describedby')).toBe(
          `${HILOS_IMPERSONATION_STRIP_TEXT_ID} ${HILOS_CONNECTION_STATUS_TEXT_ID}`,
        )
        await view.flush(() => feed.state('connected'))
        expect(action.disabled).toBe(true)
        expect(action.getAttribute('aria-describedby')).toBe(
          HILOS_IMPERSONATION_STRIP_TEXT_ID,
        )
        expect(get<HTMLButtonElement>(view.root, 'page-open').disabled).toBe(
          false,
        )
        expect(
          get<HTMLButtonElement>(view.root, 'impersonation-stop').disabled,
        ).toBe(false)
      })
    })
  })
}
