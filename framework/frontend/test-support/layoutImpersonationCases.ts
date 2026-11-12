import {
  DEFAULT_IMPERSONATION_POLICY,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  ADMITTED,
  FROZEN,
  layoutChromeConnection,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import { layoutSessionControlsSession } from './layoutSessionControlsFixtures.js'

type LayoutImpersonationRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}
type Session = Awaited<ReturnType<typeof layoutSessionControlsSession>>

const PLAIN = { entities: { currentUser: { id: 1, name: 'Ada' } } }
const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}

function takeoverWith(viewOnly: boolean): Record<string, unknown> {
  return {
    ...TAKEOVER,
    data: {
      impersonationPolicy: { ...DEFAULT_IMPERSONATION_POLICY, viewOnly },
    },
  }
}

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Shared strip behavior over raw session inputs and each SDK's real Layout. */
export function registerLayoutImpersonationCases(
  sdk: string,
  runner: LayoutImpersonationRunner,
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

  describe(`HilosLayout impersonation strip on ${sdk}`, () => {
    it('I01 draws no strip for a plain session', async () => {
      await withLayout(PLAIN, ({ root }) => {
        expect(part(root, 'impersonation-banner')).toBeNull()
      })
    })

    it('I02 names the user the session acts as while impersonated', async () => {
      await withLayout(TAKEOVER, ({ root }) => {
        const strip = part(root, 'impersonation-banner')
        expect(strip).not.toBeNull()
        expect(strip!.textContent).toContain('You are impersonating')
        expect(strip!.querySelector('strong')?.textContent).toBe('Bob')
        expect(part(strip!, 'impersonation-stop')?.textContent?.trim()).toBe(
          'Stop',
        )
      })
    })

    it('I03 keeps protected-mode, impersonation and project strips together', async () => {
      await withLayout(
        TAKEOVER,
        ({ root }) => {
          const region = part(root, 'app-banner')
          expect(region).not.toBeNull()
          for (const id of [
            'protected-mode-banner',
            'impersonation-banner',
            'test-banner',
          ]) {
            expect(region!.querySelectorAll(`[data-id="${id}"]`)).toHaveLength(
              1,
            )
          }
        },
        {
          connection: layoutChromeConnection(ADMITTED),
          banner: { dataId: 'test-banner', text: 'A trial notice' },
        },
      )
    })

    it('I04 draws no strip under the maintenance surface', async () => {
      await withLayout(
        TAKEOVER,
        ({ root }) => {
          expect(part(root, 'impersonation-banner')).toBeNull()
        },
        { connection: layoutChromeConnection(FROZEN) },
      )
    })

    it('I05 tracks Stop and guards repeat clicks until its success reply', async () => {
      await withLayout(TAKEOVER, async (view, session) => {
        const stop = part(view.root, 'impersonation-stop') as HTMLButtonElement
        await view.flush(() => stop.click())
        expect(session.sent).toHaveLength(1)
        expect(session.sent[0]?.action).toBe('hilos_impersonate_stop')
        expect(session.sent[0]?.data).toEqual({})
        expect(session.sent[0]?.requestId).toEqual(expect.any(String))
        expect(session.sent[0]?.requestId).not.toBe('')
        expect(stop.disabled).toBe(false)
        expect(stop.getAttribute('aria-disabled')).toBe('true')
        expect(stop.getAttribute('aria-busy')).toBe('true')
        await view.flush(() => stop.click())
        expect(session.sent).toHaveLength(1)
        await view.flush(() =>
          session.succeed(
            session.sent[0]?.requestId,
            IMPERSONATION_ACTION_STOP,
          ),
        )
        const readyStop = part(
          view.root,
          'impersonation-stop',
        ) as HTMLButtonElement
        expect(readyStop.disabled).toBe(false)
        expect(readyStop.getAttribute('aria-disabled')).toBeNull()
        expect(readyStop.getAttribute('aria-busy')).toBeNull()
        expect(hilosToasts.toasts.get()).toEqual([])
      })
    })

    it('I06 leaves the strip standing and toasts the exact refusal once', async () => {
      await withLayout(TAKEOVER, async (view, session) => {
        const stop = part(view.root, 'impersonation-stop') as HTMLButtonElement
        await view.flush(() => stop.click())
        await view.flush(() =>
          session.refuse(
            session.sent[0]?.requestId,
            'Session is not impersonating',
            IMPERSONATION_ACTION_STOP,
          ),
        )
        expect(part(view.root, 'impersonation-banner')).not.toBeNull()
        expect(
          (part(view.root, 'impersonation-stop') as HTMLButtonElement).disabled,
        ).toBe(false)
        expect(
          hilosToasts.toasts
            .get()
            .map((toast) => [toast.severity, toast.message]),
        ).toEqual([['error', 'Session is not impersonating']])
      })
    })

    it('I07 describes the view-only page lock while keeping Stop available', async () => {
      await withLayout(
        takeoverWith(true),
        async (view, session) => {
          const strip = part(view.root, 'impersonation-banner')
          const text = strip?.querySelector(
            `#${HILOS_IMPERSONATION_STRIP_TEXT_ID}`,
          )
          expect(text).not.toBeNull()
          expect(text?.textContent).toContain('You are impersonating')
          expect(
            Array.from(text!.querySelectorAll('span')).some(
              (node) => node.textContent?.trim() === 'view only',
            ),
          ).toBe(true)
          expect(strip?.querySelector('strong')?.textContent).toBe('Bob')
          const stop = part(
            view.root,
            'impersonation-stop',
          ) as HTMLButtonElement
          expect(stop.disabled).toBe(false)
          expect(stop.getAttribute('aria-describedby')).toBeNull()
          const action = part(view.root, 'page-action') as HTMLButtonElement
          expect(action.disabled).toBe(true)
          expect(action.getAttribute('aria-describedby')).toBe(
            HILOS_IMPERSONATION_STRIP_TEXT_ID,
          )
          await view.flush(() => stop.click())
          expect(session.sent[0]?.action).toBe('hilos_impersonate_stop')
          await view.flush(() =>
            session.succeed(
              session.sent[0]?.requestId,
              IMPERSONATION_ACTION_STOP,
            ),
          )
        },
        { connection: layoutChromeConnection(), pageAction: true },
      )
    })

    it('I08 follows a live policy change from acting to view only', async () => {
      await withLayout(
        takeoverWith(false),
        async (view, session) => {
          const marker = () =>
            Array.from(
              part(view.root, 'impersonation-banner')!.querySelectorAll('span'),
            ).find((node) => node.textContent?.trim() === 'view only')
          const action = () =>
            part(view.root, 'page-action') as HTMLButtonElement
          expect(marker()).toBeUndefined()
          expect(action().disabled).toBe(false)
          await view.flush(() => session.handshake(takeoverWith(true)))
          expect(marker()?.textContent?.trim()).toBe('view only')
          expect(action().disabled).toBe(true)
        },
        { connection: layoutChromeConnection(), pageAction: true },
      )
    })

    it('I09 adds no polite live region when the strip goes up', async () => {
      const live = '[role="status"][aria-live="polite"]'
      let liveWithout = 0
      // The first native root and its session are gone before publishing takeover.
      await withLayout(PLAIN, ({ root }) => {
        liveWithout = root.querySelectorAll(live).length
      })
      await withLayout(TAKEOVER, ({ root }) => {
        expect(part(root, 'impersonation-banner')).not.toBeNull()
        expect(root.querySelectorAll(live).length).toBe(liveWithout)
      })
    })
  })
}
