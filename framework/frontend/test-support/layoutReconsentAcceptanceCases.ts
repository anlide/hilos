import {
  hilosLegalReconsent,
  hilosToasts,
  type HilosConnection,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { reconsentContent } from '../core/test/legal/reconsentFixture.js'
import {
  layoutChromeConnection,
  layoutChromeFeed,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutReconsentHandshake,
  layoutReconsentSession,
} from './layoutReconsentFixtures.js'

type ReconsentAcceptanceRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
  createBusyRetryCheckpoint?: () => (
    root: ParentNode,
    phase: 'ready' | 'busy0' | 'busy299' | 'busy300' | 'refused',
  ) => void
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

function lastRequest(session: ReconsentSession) {
  const request = session.sent.at(-1)
  if (request === undefined) throw new Error('Missing legal request')
  return request
}

/** Register the legal acceptance lock, success and refusal on each real SDK. */
export function registerLayoutReconsentAcceptanceCases(
  sdk: string,
  runner: ReconsentAcceptanceRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, vi } = runner
  const windowFacts = {
    window: [{ document: 'terms', deadline: '2026-11-10' }],
  } satisfies NonNullable<Parameters<typeof layoutReconsentHandshake>[0]>

  async function withCase(
    steps: (view: LayoutChromeView, session: ReconsentSession) => Promise<void>,
    connection?: HilosConnection,
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
      view = await mount({ connection: connection ?? layoutChromeConnection() })
      const activeSession = session
      await view.flush(() =>
        activeSession.handshake(layoutReconsentHandshake(windowFacts)),
      )
      expect(
        part(view.root.ownerDocument, 'legal-reconsent-modal'),
      ).not.toBeNull()
      const read = lastRequest(session)
      expect(read.action).toBe('hilos_legal_reconsent')
      expect(read.data).toEqual({})
      expect(read.requestId).toEqual(expect.any(String))
      expect(read.requestId).not.toBe('')
      await view.flush(() =>
        activeSession.succeed(read.requestId, read.action, reconsentContent()),
      )
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
      throw new AggregateError(errors, 'Legal acceptance case cleanup failed')
  }

  describe(`HilosLayout legal acceptance on ${sdk}`, () => {
    it('UR05 holds the acceptance window and exact Accept focus through 299+1 ms, then closes on success', async () => {
      try {
        // Keep calendar time intact; only the request and native view timers move.
        vi.useFakeTimers({
          toFake: [
            'setTimeout',
            'clearTimeout',
            'setInterval',
            'clearInterval',
          ],
        })
        await withCase(async (view, session) => {
          const document = view.root.ownerDocument
          const accept = required<HTMLButtonElement>(
            document,
            'legal-reconsent-accept',
          )
          const spinner = () => part(accept, 'loading-button-spinner')
          const accepts = () =>
            session.sent.filter(({ action }) => action === 'hilos_legal_accept')

          await view.flush(() => {
            accept.focus()
            accept.click()
          })
          expect(accepts()).toHaveLength(1)
          const accepted = lastRequest(session)
          expect(accepted.action).toBe('hilos_legal_accept')
          expect(accepted.data).toEqual({
            acceptedRevisions: { terms: '2026-09-27', privacy: '2026-09-27' },
          })
          expect(accepted.requestId).toEqual(expect.any(String))
          expect(accepted.requestId).not.toBe('')
          expect(accept.getAttribute('aria-busy')).toBe('true')
          expect(accept.getAttribute('aria-disabled')).toBe('true')
          expect(accept.matches(':disabled')).toBe(false)
          expect(accept.closest('fieldset')?.getAttribute('data-id')).toBe(
            'modal-interaction-scope',
          )
          expect(spinner()).toBeNull()
          expect(document.activeElement).toBe(accept)
          expect(
            required(document, 'legal-reconsent-full-text').closest('fieldset')
              ?.disabled,
          ).toBe(true)
          const later = required<HTMLButtonElement>(
            document,
            'legal-reconsent-later',
          )
          const refuse = required<HTMLButtonElement>(
            document,
            'legal-reconsent-refuse',
          )
          const close = required<HTMLButtonElement>(document, 'modal-close')
          expect(later.disabled).toBe(true)
          expect(refuse.disabled).toBe(true)
          expect(close.disabled).toBe(true)

          const dialog = required(
            document,
            'legal-reconsent-modal',
          ).closest<HTMLElement>('[role="dialog"]')
          if (dialog === null) throw new Error('Missing legal dialog')
          await view.flush(() => {
            close.click()
            dialog.dispatchEvent(
              new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
            )
            dialog.click()
            later.click()
            refuse.click()
          })
          expect(part(document, 'legal-reconsent-modal')).not.toBeNull()
          expect(part(document, 'modal-confirm')).toBeNull()
          expect(part(document, 'legal-reconsent-refuse-step')).toBeNull()

          await view.flush(() => vi.advanceTimersByTime(299))
          expect(spinner()).toBeNull()
          await view.flush(() => vi.advanceTimersByTime(1))
          expect(spinner()).not.toBeNull()
          expect(accept.matches(':disabled')).toBe(false)
          await view.flush(() => accept.click())
          expect(accepts()).toHaveLength(1)
          expect(document.activeElement).toBe(accept)

          await view.flush(() =>
            session.succeed(accepted.requestId, accepted.action),
          )
          expect(part(document, 'legal-reconsent-modal')).toBeNull()
        })
      } finally {
        // Native unmount and source disconnect/drain finish under the same clock.
        vi.useRealTimers()
      }
    })

    it('UR06 holds the selected comparison through acceptance, rereads a refusal and closes after retry', async () => {
      try {
        vi.useFakeTimers({
          toFake: [
            'setTimeout',
            'clearTimeout',
            'setInterval',
            'clearInterval',
          ],
        })
        await withCase(async (view, session) => {
          const document = view.root.ownerDocument
          const compare = required(document, 'legal-reconsent-compare')
          expect(compare.getAttribute('data-document')).toBe('terms')
          await view.flush(() => compare.click())
          const modal = required(document, 'legal-reconsent-modal')
          const body = modal.querySelector('fieldset')
          if (body === null) throw new Error('Missing legal body')
          const comparison = required(document, 'legal-changes')
          const accept = required(document, 'legal-reconsent-accept')

          await view.flush(() => {
            accept.focus()
            accept.click()
          })
          const accepted = lastRequest(session)
          expect(accepted.action).toBe('hilos_legal_accept')
          expect(accepted.data).toEqual({
            acceptedRevisions: { terms: '2026-09-27', privacy: '2026-09-27' },
          })
          expect(accepted.requestId).toEqual(expect.any(String))
          expect(accepted.requestId).not.toBe('')
          expect(accept.getAttribute('aria-busy')).toBe('true')
          expect(accept.getAttribute('aria-disabled')).toBe('true')
          expect(accept.matches(':disabled')).toBe(false)
          expect(document.activeElement).toBe(accept)
          expect(part(accept, 'loading-button-spinner')).toBeNull()
          expect(part(document, 'legal-reconsent-modal')).toBe(modal)
          expect(modal.querySelector('fieldset')).toBe(body)
          expect(part(document, 'legal-changes')).toBe(comparison)
          const lockedControls = [
            'modal-close',
            'legal-reconsent-later',
            'legal-reconsent-refuse',
            'legal-reconsent-back',
          ].map((id) => required(document, id))
          for (const control of lockedControls) {
            expect(control.matches(':disabled')).toBe(true)
          }
          const backdrop = required(document, 'modal')
          await view.flush(() => {
            for (const control of lockedControls) control.click()
            accept.click()
            backdrop.click()
            backdrop.dispatchEvent(
              new KeyboardEvent('keydown', {
                key: 'Escape',
                bubbles: true,
                cancelable: true,
              }),
            )
            vi.advanceTimersByTime(299)
          })
          expect(part(document, 'legal-reconsent-modal')).toBe(modal)
          expect(modal.querySelector('fieldset')).toBe(body)
          expect(part(document, 'legal-changes')).toBe(comparison)
          expect(session.sent).toHaveLength(2)
          expect(part(accept, 'loading-button-spinner')).toBeNull()
          expect(document.activeElement).toBe(accept)
          await view.flush(() => vi.advanceTimersByTime(1))
          expect(part(accept, 'loading-button-spinner')).not.toBeNull()
          expect(document.activeElement).toBe(accept)

          await view.flush(() =>
            session.refuse(
              accepted.requestId,
              'The terms moved.',
              accepted.action,
            ),
          )
          expect(part(document, 'legal-reconsent-modal')).toBe(modal)
          const refusal = required(document, 'legal-reconsent-error')
          expect(refusal.textContent).toContain('The terms moved.')
          expect(body.disabled).toBe(true)
          expect(
            required(document, 'legal-reconsent-error-details').matches(
              ':disabled',
            ),
          ).toBe(true)
          const reread = lastRequest(session)
          expect(reread.action).toBe('hilos_legal_reconsent')
          expect(reread.requestId).toEqual(expect.any(String))
          expect(reread.requestId).not.toBe('')
          expect(reread.requestId).not.toBe(accepted.requestId)
          await view.flush(() =>
            session.succeed(
              reread.requestId,
              reread.action,
              reconsentContent(),
            ),
          )
          expect(part(document, 'legal-reconsent-modal')).toBe(modal)
          expect(modal.querySelector('fieldset')).toBe(body)
          expect(part(document, 'legal-reconsent-error')).toBe(refusal)
          expect(refusal.textContent).toContain('The terms moved.')
          expect(body.disabled).toBe(false)
          expect(
            required(document, 'legal-reconsent-error-details').matches(
              ':disabled',
            ),
          ).toBe(false)
          expect(required(document, 'modal-close').matches(':disabled')).toBe(
            false,
          )
          expect(
            required(document, 'legal-reconsent-later').matches(':disabled'),
          ).toBe(false)
          expect(part(document, 'legal-reconsent-document')).not.toBeNull()
          expect(session.sent).toHaveLength(3)

          await view.flush(() =>
            required(document, 'legal-reconsent-accept').click(),
          )
          const retry = lastRequest(session)
          expect(retry.action).toBe('hilos_legal_accept')
          expect(session.sent).toHaveLength(4)
          await view.flush(() => session.succeed(retry.requestId, retry.action))
          expect(part(document, 'legal-reconsent-modal')).toBeNull()
        })
      } finally {
        // Native unmount and source release/drain precede timer restoration.
        vi.useRealTimers()
      }
    })

    it('UR07 rereads after a refused acceptance before unlocking the window for Later', async () => {
      await withCase(async (view, session) => {
        const document = view.root.ownerDocument
        await view.flush(() =>
          required<HTMLButtonElement>(
            document,
            'legal-reconsent-accept',
          ).click(),
        )
        const accepted = lastRequest(session)
        expect(accepted.action).toBe('hilos_legal_accept')
        await view.flush(() =>
          session.refuse(
            accepted.requestId,
            'The terms changed again.',
            accepted.action,
          ),
        )

        const reread = lastRequest(session)
        expect(reread.action).toBe('hilos_legal_reconsent')
        expect(reread.requestId).toEqual(expect.any(String))
        expect(reread.requestId).not.toBe('')
        expect(reread.requestId).not.toBe(accepted.requestId)
        expect(
          required<HTMLButtonElement>(document, 'modal-close').disabled,
        ).toBe(true)
        await view.flush(() =>
          session.succeed(reread.requestId, reread.action, reconsentContent()),
        )

        expect(
          required(document, 'legal-reconsent-error').textContent,
        ).toContain('The terms changed again.')
        expect(
          required<HTMLButtonElement>(document, 'modal-close').disabled,
        ).toBe(false)
        expect(
          required(document, 'legal-reconsent-full-text').closest('fieldset')
            ?.disabled,
        ).toBe(false)
        const later = required<HTMLButtonElement>(
          document,
          'legal-reconsent-later',
        )
        expect(later.disabled).toBe(false)
        await view.flush(() => later.click())
        expect(part(document, 'legal-reconsent-modal')).toBeNull()
      })
    })

    it('UR05/UR07 preserves nodes and focus at 0/299/300 ms, rereads a refusal and closes after retry', async () => {
      try {
        // One scheduler owns setup, acceptance, reread, retry and native cleanup.
        vi.useFakeTimers({
          toFake: [
            'setTimeout',
            'clearTimeout',
            'setInterval',
            'clearInterval',
          ],
        })
        await withCase(async (view, session) => {
          const document = view.root.ownerDocument
          const modal = required(document, 'modal')
          const content = required(document, 'legal-reconsent-document')
          const errorSlot = required(document, 'legal-reconsent-error-slot')
          const checkpoint = runner.createBusyRetryCheckpoint?.()
          checkpoint?.(document, 'ready')
          const fullText = required(document, 'legal-reconsent-full-text')
          const compare = required(document, 'legal-reconsent-compare')
          const later = required(document, 'legal-reconsent-later')
          const refuse = required(document, 'legal-reconsent-refuse')
          const accept = required(document, 'legal-reconsent-accept')
          const close = required(document, 'modal-close')
          const calls = session.sent.length

          await view.flush(() => {
            accept.focus()
            accept.click()
          })
          const accepted = lastRequest(session)
          expect(accepted.action).toBe('hilos_legal_accept')
          expect(accepted.data).toEqual({
            acceptedRevisions: { terms: '2026-09-27', privacy: '2026-09-27' },
          })
          expect(accepted.requestId).toEqual(expect.any(String))
          expect(accepted.requestId).not.toBe('')
          expect(session.sent).toHaveLength(calls + 1)

          async function probe(
            phase: 'busy0' | 'busy299' | 'busy300',
          ): Promise<void> {
            expect(part(document, 'modal')).toBe(modal)
            expect(part(document, 'legal-reconsent-document')).toBe(content)
            expect(part(document, 'legal-reconsent-error-slot')).toBe(errorSlot)
            checkpoint?.(document, phase)
            expect(accept.matches(':disabled')).toBe(false)
            expect(accept.getAttribute('aria-busy')).toBe('true')
            expect(document.activeElement).toBe(accept)
            await view.flush(() => {
              for (const control of [fullText, compare, later, refuse, close]) {
                expect(control.matches(':disabled')).toBe(true)
                control.click()
              }
              accept.click()
              modal.dispatchEvent(
                new KeyboardEvent('keydown', {
                  key: 'Escape',
                  bubbles: true,
                  cancelable: true,
                }),
              )
              modal.click()
            })
            expect(part(document, 'modal-confirm')).toBeNull()
            expect(part(document, 'legal-reconsent-document')).toBe(content)
            expect(part(document, 'legal-reconsent-refuse-step')).toBeNull()
            expect(session.sent).toHaveLength(calls + 1)
          }

          await probe('busy0')
          expect(part(accept, 'loading-button-spinner')).toBeNull()
          await view.flush(() => vi.advanceTimersByTime(299))
          await probe('busy299')
          expect(part(accept, 'loading-button-spinner')).toBeNull()
          await view.flush(() => vi.advanceTimersByTime(1))
          await probe('busy300')
          expect(
            accept.querySelectorAll('[data-id="loading-button-spinner"]'),
          ).toHaveLength(1)

          await view.flush(() =>
            session.refuse(
              accepted.requestId,
              'The terms changed. Review them again.',
              accepted.action,
            ),
          )
          const reread = lastRequest(session)
          expect(reread.action).toBe('hilos_legal_reconsent')
          expect(reread.requestId).toEqual(expect.any(String))
          expect(reread.requestId).not.toBe('')
          expect(reread.requestId).not.toBe(accepted.requestId)
          expect(part(document, 'modal')).toBe(modal)
          expect(part(document, 'legal-reconsent-error-slot')).toBe(errorSlot)
          checkpoint?.(document, 'refused')
          expect(
            required(errorSlot, 'legal-reconsent-error').textContent,
          ).toContain('The terms changed. Review them again.')

          await view.flush(() =>
            session.succeed(
              reread.requestId,
              reread.action,
              reconsentContent(),
            ),
          )
          expect(part(document, 'modal')).toBe(modal)
          expect(
            required(document, 'legal-reconsent-later').matches(':disabled'),
          ).toBe(false)
          expect(
            required(document, 'legal-reconsent-refuse').matches(':disabled'),
          ).toBe(false)
          expect(required(document, 'modal-close').matches(':disabled')).toBe(
            false,
          )
          expect(
            required(document, 'legal-reconsent-accept').matches(':disabled'),
          ).toBe(false)
          expect(session.sent).toHaveLength(calls + 2)
          expect(part(document, 'legal-reconsent-document')).not.toBeNull()

          await view.flush(() =>
            required(document, 'legal-reconsent-accept').click(),
          )
          const retry = lastRequest(session)
          expect(retry.action).toBe('hilos_legal_accept')
          expect(session.sent).toHaveLength(calls + 3)
          await view.flush(() => session.succeed(retry.requestId, retry.action))
          expect(part(document, 'legal-reconsent-modal')).toBeNull()
          expect(part(document, 'modal-confirm')).toBeNull()
        })
      } finally {
        // Unmount and source release/drain finish before restoring real timers.
        vi.useRealTimers()
      }
    })

    it('UR08 keeps the selected text mounted and subjects Accept to the independent connection lock', async () => {
      const feed = layoutChromeFeed()
      await withCase(async (view, session) => {
        const document = view.root.ownerDocument
        const fullText = required(document, 'legal-reconsent-full-text')
        expect(fullText.getAttribute('data-document')).toBe('terms')
        await view.flush(() => fullText.click())
        const text = required(document, 'legal-revision-text')
        expect(text).not.toBeNull()
        const back = required(document, 'legal-reconsent-back')
        const accept = required(document, 'legal-reconsent-accept')

        await view.flush(() => accept.click())
        const accepted = lastRequest(session)
        expect(accepted.action).toBe('hilos_legal_accept')
        expect(accepted.requestId).toEqual(expect.any(String))
        expect(accepted.requestId).not.toBe('')
        expect(back.matches(':disabled')).toBe(true)
        await view.flush(() => back.click())
        expect(part(document, 'legal-revision-text')).toBe(text)

        await view.flush(() => feed.state('disconnected'))
        expect(part(document, 'legal-revision-text')).toBe(text)
        expect(part(document, 'legal-reconsent-accept')).toBe(accept)
        expect(accept.matches(':disabled')).toBe(true)
        const sent = session.sent.length
        await view.flush(() => accept.click())
        expect(session.sent).toHaveLength(sent)

        await view.flush(() =>
          session.succeed(accepted.requestId, accepted.action),
        )
        expect(part(document, 'legal-reconsent-modal')).toBeNull()
        expect(feed.connection.state).toBe('disconnected')
      }, feed.connection)
    })

    it('UR09 removes the busy legal window when the session leaves', async () => {
      await withCase(async (view, session) => {
        const document = view.root.ownerDocument
        await view.flush(() =>
          required<HTMLButtonElement>(
            document,
            'legal-reconsent-accept',
          ).click(),
        )

        const accepted = lastRequest(session)
        expect(accepted.action).toBe('hilos_legal_accept')
        expect(accepted.requestId).toEqual(expect.any(String))
        expect(accepted.requestId).not.toBe('')
        expect(part(document, 'legal-reconsent-modal')).not.toBeNull()

        await view.flush(() =>
          session.handshake(layoutReconsentHandshake(null)),
        )
        expect(part(document, 'legal-reconsent-modal')).toBeNull()
      })
    })
  })
}
