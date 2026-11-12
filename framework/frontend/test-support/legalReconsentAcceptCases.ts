import type * as Vitest from 'vitest'
import {
  RECONSENT_NOW,
  reconsentContent,
} from '../core/test/legal/reconsentFixture.js'
import {
  withLegalReconsentView,
  type MountLegalReconsent,
} from './legalReconsentPreviewCases.js'

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>

/** Exercise Accept's own busy timeline through each SDK's native component. */
export function registerLegalReconsentAcceptCases(
  sdk: string,
  runner: Runner,
  mount: MountLegalReconsent,
): void {
  const { describe, it, expect, vi } = runner

  describe(`HilosLegalReconsent acceptance on ${sdk}`, () => {
    it('blocks an initially busy Accept until the caller releases it', async () => {
      const onAccept = vi.fn()
      try {
        vi.useFakeTimers()
        await withLegalReconsentView(
          runner,
          mount,
          {
            variant: 'window',
            content: reconsentContent(),
            view: { kind: 'changes' },
            busy: true,
            now: RECONSENT_NOW,
            onAccept,
          },
          async ({ root, update, interact }) => {
            const accept = root.querySelector<HTMLButtonElement>(
              '[data-id="legal-reconsent-accept"]',
            )!
            expect(accept).toBeInstanceOf(HTMLButtonElement)
            expect(accept.getAttribute('aria-busy')).toBe('true')
            expect(accept.getAttribute('aria-disabled')).toBe('true')
            expect(accept.disabled).toBe(false)
            expect(
              accept.querySelector('[data-id="loading-button-spinner"]'),
            ).toBeNull()
            await interact(() => accept.click())
            expect(onAccept).not.toHaveBeenCalled()

            await interact(() => vi.advanceTimersByTime(300))
            expect(
              accept.querySelector('[data-id="loading-button-spinner"]'),
            ).not.toBeNull()
            await update({ busy: false })
            expect(accept.getAttribute('aria-disabled')).toBeNull()
            await interact(() => accept.click())
            expect(onAccept).toHaveBeenCalledTimes(1)
          },
        )
      } finally {
        vi.useRealTimers()
      }
    })

    it('keeps focused Accept active through the 299/300 ms spinner boundary and disables it during reading', async () => {
      const onAccept = vi.fn()
      try {
        vi.useFakeTimers()
        await withLegalReconsentView(
          runner,
          mount,
          {
            variant: 'window',
            content: reconsentContent(),
            view: { kind: 'changes' },
            busy: false,
            now: RECONSENT_NOW,
            onAccept,
          },
          async ({ root, update, interact }) => {
            const accept = root.querySelector<HTMLButtonElement>(
              '[data-id="legal-reconsent-accept"]',
            )!
            expect(accept).toBeInstanceOf(HTMLButtonElement)
            const content = root.querySelector(
              '[data-id="legal-reconsent-document"]',
            )!
            expect(content).toBeInstanceOf(HTMLElement)
            await interact(() => accept.focus())
            await update({ busy: true })
            expect(accept.getAttribute('aria-busy')).toBe('true')
            expect(accept.getAttribute('aria-disabled')).toBe('true')
            expect(accept.disabled).toBe(false)
            expect(accept.matches(':disabled')).toBe(false)
            expect(document.activeElement).toBe(accept)
            expect(
              accept.querySelector('[data-id="loading-button-spinner"]'),
            ).toBeNull()
            await interact(() => accept.click())
            expect(onAccept).not.toHaveBeenCalled()

            await interact(() => vi.advanceTimersByTime(299))
            expect(
              accept.querySelector('[data-id="loading-button-spinner"]'),
            ).toBeNull()
            expect(accept.matches(':disabled')).toBe(false)
            expect(document.activeElement).toBe(accept)
            expect(
              root.querySelector('[data-id="legal-reconsent-document"]'),
            ).toBe(content)

            await interact(() => vi.advanceTimersByTime(1))
            expect(
              accept.querySelector('[data-id="loading-button-spinner"]'),
            ).not.toBeNull()
            expect(
              accept.querySelectorAll('[data-id="loading-button-spinner"]'),
            ).toHaveLength(1)
            expect(accept.matches(':disabled')).toBe(false)
            expect(document.activeElement).toBe(accept)

            await update({ busy: false })
            expect(accept.getAttribute('aria-disabled')).toBeNull()
            await interact(() => accept.click())
            expect(onAccept).toHaveBeenCalledTimes(1)

            await update({ loading: true })
            expect(accept.disabled).toBe(true)
            expect(accept.matches(':disabled')).toBe(true)
            expect(accept.getAttribute('aria-busy')).toBeNull()
          },
        )
      } finally {
        vi.useRealTimers()
      }
    })
  })
}
