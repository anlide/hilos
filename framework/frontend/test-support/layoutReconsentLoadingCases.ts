import { hilosLegalReconsent, hilosToasts } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  layoutChromeConnection,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutReconsentHandshake,
  layoutReconsentSession,
} from './layoutReconsentFixtures.js'

type ReconsentLoadingRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}

type ReconsentSession = Awaited<ReturnType<typeof layoutReconsentSession>>

function part<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T | null {
  return root.querySelector<T>(`[data-id="${id}"]`)
}

function required<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = part<T>(root, id)
  if (element === null) throw new Error(`Missing ${id}`)
  return element
}

/** Register shared legal entrance and loading-close behavior on a native SDK. */
export function registerLayoutReconsentLoadingCases(
  sdk: string,
  runner: ReconsentLoadingRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner
  const windowFacts = {
    window: [{ document: 'terms', deadline: '2026-11-10' }],
  } satisfies NonNullable<Parameters<typeof layoutReconsentHandshake>[0]>

  async function withCase(
    steps: (view: LayoutChromeView, session: ReconsentSession) => Promise<void>,
  ): Promise<void> {
    const errors: unknown[] = []
    let session: ReconsentSession | undefined
    let view: LayoutChromeView | undefined

    async function attempt(cleanup: () => void | Promise<void>): Promise<void> {
      try {
        await cleanup()
      } catch (error) {
        errors.push(error)
      }
    }

    try {
      session = await layoutReconsentSession()
      session.handshake(layoutReconsentHandshake(null))
      view = await mount({ connection: layoutChromeConnection() })
      await steps(view, session)
    } catch (error) {
      errors.push(error)
    } finally {
      const mounted = view
      if (mounted !== undefined) await attempt(() => mounted.unmount())

      const activeSession = session
      if (activeSession !== undefined) {
        await attempt(async () => {
          if (runner.releaseBindings)
            await runner.releaseBindings(activeSession.release)
          else await activeSession.release()
        })
      }

      await attempt(() => hilosLegalReconsent.reset())
      await attempt(() => hilosToasts.clear())
    }

    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Legal re-consent case cleanup failed')
  }

  describe(`HilosLayout legal entrance and loading close on ${sdk}`, () => {
    it('UR03 raises the window on sign-in and opens it from the icon after Later', async () => {
      await withCase(async (view, session) => {
        const document = view.root.ownerDocument
        expect(part(view.root, 'legal-reconsent-icon')).toBeNull()

        await view.flush(() =>
          session.handshake(layoutReconsentHandshake(windowFacts)),
        )

        expect(part(document, 'legal-reconsent-modal')).not.toBeNull()
        expect(session.sent.at(-1)?.action).toBe('hilos_legal_reconsent')

        await view.flush(() =>
          required<HTMLButtonElement>(
            document,
            'legal-reconsent-later',
          ).click(),
        )
        expect(part(document, 'legal-reconsent-modal')).toBeNull()

        await view.flush(() =>
          required(view.root, 'legal-reconsent-icon').click(),
        )
        expect(part(document, 'legal-reconsent-modal')).not.toBeNull()
      })
    })

    it.each(['header', 'escape', 'backdrop', 'later'] as const)(
      'UR04 allows the %s gesture to close while re-consent content is loading',
      async (gesture) => {
        await withCase(async (view, session) => {
          const document = view.root.ownerDocument
          await view.flush(() =>
            session.handshake(layoutReconsentHandshake(windowFacts)),
          )

          expect(part(document, 'legal-reconsent-modal')).not.toBeNull()
          expect(part(document, 'legal-reconsent-loading')).not.toBeNull()
          expect(
            required(document, 'legal-reconsent-accept').matches(':disabled'),
          ).toBe(true)
          const modalClose = required<HTMLButtonElement>(
            document,
            'modal-close',
          )
          expect(modalClose.matches(':disabled')).toBe(false)
          const modal = required(document, 'modal')

          let close: () => void
          if (gesture === 'later') {
            expect(session.sent.at(-1)?.action).toBe('hilos_legal_reconsent')
            const later = required<HTMLButtonElement>(
              document,
              'legal-reconsent-later',
            )
            expect(later.matches(':disabled')).toBe(false)
            close = () => later.click()
          } else if (gesture === 'header') {
            close = () => modalClose.click()
          } else if (gesture === 'escape') {
            close = () =>
              modal.dispatchEvent(
                new KeyboardEvent('keydown', {
                  key: 'Escape',
                  bubbles: true,
                }),
              )
          } else {
            close = () => modal.click()
          }

          await view.flush(close)
          expect(part(document, 'legal-reconsent-modal')).toBeNull()
          expect(session.sent.map(({ action }) => action)).toEqual([
            'hilos_legal_reconsent',
          ])
        })
      },
    )

    it('UR04 preserves the linked close, reopen and Escape sequence while loading', async () => {
      await withCase(async (view, session) => {
        const document = view.root.ownerDocument
        await view.flush(() =>
          session.handshake(layoutReconsentHandshake(windowFacts)),
        )

        expect(part(document, 'legal-reconsent-modal')).not.toBeNull()
        expect(part(document, 'legal-reconsent-loading')).not.toBeNull()
        expect(
          required<HTMLButtonElement>(document, 'modal-close').matches(
            ':disabled',
          ),
        ).toBe(false)
        const legalBody = required(
          document,
          'legal-reconsent',
        ).querySelector<HTMLFieldSetElement>('fieldset')
        if (legalBody === null) throw new Error('Missing legal re-consent body')
        expect(legalBody.disabled).toBe(false)

        await view.flush(() =>
          required<HTMLButtonElement>(document, 'modal-close').click(),
        )
        expect(part(document, 'legal-reconsent-modal')).toBeNull()

        await view.flush(() =>
          required(view.root, 'legal-reconsent-icon').click(),
        )
        expect(part(document, 'legal-reconsent-loading')).not.toBeNull()
        const reopenedModal = required(document, 'modal')
        await view.flush(() =>
          reopenedModal.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
          ),
        )

        expect(part(document, 'legal-reconsent-modal')).toBeNull()
        expect(part(document, 'modal-confirm')).toBeNull()
      })
    })
  })
}
